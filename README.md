# fx-remittance-ledger

An event-sourced vertical slice of an FX remittance pipeline (BRL → USD over crypto rails),
built as a take-home. The system doesn't perform the exchange itself — it **orchestrates**
providers and guarantees no cent is lost and every step is auditable.

**Stack:** Laravel 13 · PHP 8.3 · PostgreSQL (event store on `jsonb`) ·
`spatie/laravel-event-sourcing` · Pest · Docker Compose.

> Work in progress — the sections below are filled in as the slice lands.

## Domain

_TODO (Wed): the operation as a 6-step event-sourced state machine (quote → deposit → compliance
→ conversion → settlement → payout → reconciled), plus the deviations where the engineering lives._

## Design

_TODO (Wed): event sourcing + double-entry ledger as a projection + reconciliation that only
closes when inflows = outflows + fees._

## How to run

_TODO (Wed): verified clean-clone steps — `docker compose up -d`, `php artisan migrate`, `pest`._

## What's intentionally left out (and why)

_TODO (Wed): fake providers behind interfaces, no UI, PIX/BRL only, no retry/backoff machinery —
the honest cuts made under a time box._

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
history shows the red→green rhythm.

_TODO (Wed): tighten once the slice is done — keep it to a few confident sentences._
