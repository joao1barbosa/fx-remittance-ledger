# fx-remittance-ledger

An event-sourced vertical slice of an FX remittance pipeline (BRL → USD over crypto rails),
built as a take-home. The system doesn't perform the exchange itself — it **orchestrates**
providers and guarantees no cent is lost and every step is auditable.

**Stack:** Laravel 13 · PHP 8.3 · PostgreSQL (event store on `jsonb`) ·
`spatie/laravel-event-sourcing` · Pest · Docker Compose.

## Domain

The unit of work is a single **`FxOperation`** — an event-sourced aggregate that moves through six
asynchronous steps, each emitting one immutable, past-tense fact:

**quote → deposit → compliance → conversion → settlement → payout → reconciled.**

`quote.created` prices the remittance and opens a ~15-minute window; `deposit.confirmed` records the
incoming PIX/BRL against that quote; `compliance.approved` opens the screening gate;
`conversion.executed` swaps BRL for USDC on the exchange; `settlement.completed` + `payout.completed`
off-ramp USDC to USD and deliver it to the beneficiary; `operation.reconciled` closes the operation
once its ledger balances. Every payload is minimal — each field is an eternal contract.

The engineering lives in the **deviations**, not the happy path: a deposit after the quote window
(`deposit.expired → operation.cancelled`), conversion slippage beyond tolerance (recorded as a
durable fact for reconciliation, *not* halted — the customer still gets the locked quote), a
re-delivered webhook that becomes a no-op instead of a double effect, and a reconciliation that can
**fail** (`reconciliation.discrepancy`) rather than rubber-stamp a close. The full event catalog and
per-command invariants live in `AGENTS.md`; the aggregate is `app/Domain/FxOperation/FxOperation.php`.

## Design

- **The aggregate is the only consistency boundary.** Invariants are guarded *before* any event is
  recorded — a command that violates one throws a `DomainException` and no event is born. **Failure
  is not a fact.** State is never a mutable row; it is rebuilt by replaying the event stream.
- **Purity via seams — I/O and non-determinism stay at the edge.** Anything the aggregate must not
  own (the FX rate, the compliance verdict, the exchange fill, the current time, the ledger balance)
  is computed in an application handler and passed *into* the aggregate as data. The aggregate never
  reads a clock, calls a provider, or queries a projection. Same shape everywhere:
  `CreateQuoteHandler`, `ScreenComplianceHandler`, `ConvertHandler`, `ConcludeOperationHandler` — the
  handler does the I/O, the aggregate triages. Providers sit behind ports (`ExchangeRateProvider`,
  `ComplianceProvider`, `CryptoExchange`, `LiquidityProvider`) with fake implementations.
- **Money is integer cents, always.** A currency-tagged `Money` value object; no floats anywhere,
  `jsonb` event payloads, one explicit round-half-up in the pricing formula.
- **Webhooks are idempotent at the aggregate.** The deposit webhook dedupes by `providerRef`; the
  liquidity settlement webhook by a terminal flag. A re-delivered webhook records no second fact and
  never pays a beneficiary twice — the trust boundary is a shared secret checked with `hash_equals`
  before any work.
- **The ledger is a double-entry projection over the events** (`app/Domain/Ledger/`): each posting
  line is account/debit/credit in cents, rebuilt by replay — never a manual balance UPDATE. Each
  posting balances within a single currency (no cross-currency arithmetic, no rate); an external
  `fx_exchange` account closes the conversion legs. **Reconciliation asserts every intermediate
  holding nets to zero** — a cent stuck in any holding surfaces as `reconciliation.discrepancy`.

- **The ledger is materialized into `ledger_entries` as an auditable read-model** (a Spatie projector,
  `app/Projectors/LedgerEntryProjector.php`, registered explicitly in `config/event-sourcing.php`).
  The event stream stays the source of truth; the table is a queryable projection, reconstructed by
  replay (`event-sourcing:replay` truncates and rebuilds it) and **never touched by an UPDATE**. The
  projector reuses the in-memory `LedgerProjector` for the posting rules rather than re-deriving them.
  Deliberately, **reconciliation does not read this table** — it re-projects from the events
  independently. Trusting the projection to verify itself is circular; re-deriving from the source of
  truth is precisely what lets reconcile detect a drift in the projection.

## How to run

Requires Docker and PHP 8.3 / Composer locally.

```bash
docker compose up -d          # Postgres 17 (healthcheck); on first boot creates both
                              # fx_remittance (dev) and fx_remittance_test (isolated suite)
composer install
cp .env.example .env          # already points at pgsql
php artisan key:generate
php artisan migrate           # dev DB; the test DB is migrated per-run by RefreshDatabase

./vendor/bin/pest             # the suite runs against fx_remittance_test — real jsonb/numeric
```

> If the Postgres volume predates the test-DB init script, run `docker compose down -v` once so the
> `fx_remittance_test` database is created on the next boot.

## What's intentionally left out (and why)

Everything outside the core guarantee is faked behind an interface or cut on purpose. Provider
integrations are fakes behind ports (a BaaS deposit webhook, `FakeCryptoExchange`, `FakeLiquidity`,
`FakeComplianceProvider`), so the pipeline is exercised end to end without a network. No UI — flows
are driven by application handlers and the two webhook endpoints. BRL/PIX inbound rail only; no
auth, no multi-tenancy, no retry/backoff machinery.

**Deferred infrastructure, all additive because the facts are already durable.** `settlement.failed`
classification/retry and the refund router (below) are documented but unbuilt. The **reactors** that
would push the pipeline forward on their own — compliance screening on `deposit.confirmed`, reconcile
on `payout.completed` — are not wired; each handler is invoked directly. The materialized ledger
(`ledger_entries`, see Design) carries no `currency` column — it is derivable from the account, so it
is left for when a query needs it; there are no read endpoints over the table yet either.
Webhook hardening beyond the shared secret (HMAC-over-body, dedup/replay storage) is left out.

**Late deposit → cancel, not refund (a stated assumption).** `deposit.expired` cascades to
`operation.cancelled`, not `refund.initiated`. This assumes the payment provider rejects or refunds
a deposit that lands after the quote window *upstream*, so no customer funds are ever held on our
side (call it "Mundo B"). On a raw-PIX rail ("Mundo A") a late webhook would mean funds already
settled and irreversibly received — there the correct reaction is `refund.initiated`, since cancelling
without returning the money would strand the customer's balance. I kept to the take-home's flow but
flagged the assumption deliberately: because `deposit.expired` is a durable event, swapping the
reaction to a refund reactor later is additive — no migration, no lost facts.

**Compliance screening runs as a synchronous command here; production would trigger it from a
reactor.** Before conversion the operation passes the screening gate — identity, sanctions, PEP,
adverse media. In this slice screening is an explicit command on the aggregate: the identity
provider is called in the application handler and its verdict is passed into the pure aggregate as
data, which records `compliance.approved` (the gate opens) or `compliance.review_required` (the
operation pauses — a match never proceeds on its own). Conversion refuses unless the operation is
approved. This keeps a single consistency boundary and makes the gate trivially testable.

**Two deliberate simplifications, both additive later.** (1) In production the screening call
belongs in a reactor on `deposit.confirmed` — a genuinely external, latency-bound service — so
deposit confirmation never blocks on a third party; here it is driven synchronously. (2)
`compliance.review_required` only sets the status; the manual-review resolution is not implemented.
When a human resolves a review it yields either `approved` (proceed) or `rejected`. Because the
aggregate owns the same events and the same conversion invariant either way, adding the reactor and
the review resolution later is additive — no new migration, no lost facts.

**Refund is a shared reaction with two triggers, both deferred.** Once the deposit is confirmed the
customer's BRL is held, so any downstream terminal failure must return it: `compliance.rejected` (a
review resolved against the customer) and `conversion.failed` (the exchange order could not be
filled — liquidity, timeout, rejection) both drive `refund.initiated`. That is a single router
that, on the event, dispatches to the correct provider's refund worker (per-provider, since each
rail refunds differently). Both `compliance.rejected` and `conversion.failed` are durable facts, so
the refund router is additive later — the `convert` slice records the successful path and the
slippage alert now; the failure fact and its refund reaction are a follow-up slice.

**Confirmation is terminal for the deposit step — it wins over the window.** Once a deposit is
confirmed, the operation stops watching the clock: a *second* deposit is refused as "already
confirmed" rather than re-evaluated against the window, even if it arrives after `expiresAt`. This
is a deliberate guard ordering inside `confirmDeposit` — the idempotency check (same `providerRef` →
no new fact) and the single-deposit check (different `providerRef` → refuse) both run *before* the
late-window check. So a confirmed operation can never be retroactively expired by a straggler; the
window only governs the *first* deposit. The alternative — letting a late straggler expire an
already-confirmed operation — would let a duplicate webhook unwind a settled deposit, which is
exactly the kind of lost fact event sourcing exists to prevent.

## How this was built

I used an AI coding agent as a pair, deliberately guarded by tests. The split was explicit: I own
the specs — the failing tests that encode each domain invariant — and the architectural decisions
(the event catalog, money as integer cents, choosing Laravel 13 over an advisory-blocked 11); the
agent implements against those red tests and I refactor. Test-first on the money and correctness
core is what keeps AI-written code honest; scaffolding and boilerplate were delegated. The commit
history shows the red→green rhythm — a `test(...)` commit going red, then the `feat(...)` that makes
it green — across all six steps of the state machine.
