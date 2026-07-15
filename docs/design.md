# Design

The full rationale behind the architecture. The short version lives in the [README](../README.md#-design); this is the deep dive.

## The aggregate is the only consistency boundary

Invariants are guarded *before* any event is recorded — a command that violates one throws a `DomainException` and no event is born. **Failure is not a fact.** State is never a mutable row; it is rebuilt by replaying the event stream. The whole state machine lives in one aggregate root, `app/Domain/FxOperation/FxOperation.php`, because in event sourcing the aggregate is what enforces the ordering and the invariants across every step.

## Purity via seams — I/O and non-determinism stay at the edge

Anything the aggregate must not own (the FX rate, the compliance verdict, the exchange fill, the current time, the ledger balance) is computed in an application handler and passed *into* the aggregate as data. The aggregate never reads a clock, calls a provider, or queries a projection.

The shape is the same everywhere: `CreateQuoteHandler`, `ScreenComplianceHandler`, `ConvertHandler`, `ConcludeOperationHandler` — the handler does the I/O, the aggregate triages. Providers sit behind ports (`ExchangeRateProvider`, `ComplianceProvider`, `CryptoExchange`, `LiquidityProvider`) with fake implementations, so the pipeline runs end to end without a network.

## Money is integer cents, always

A currency-tagged `Money` value object; no floats anywhere, `jsonb` event payloads, one explicit round-half-up in the pricing formula. Value objects reject invalid state in their constructor.

## Webhooks are idempotent at the aggregate

The deposit webhook dedupes by `providerRef`; the liquidity settlement webhook by a terminal flag. A re-delivered webhook records no second fact and never pays a beneficiary twice. The trust boundary is a shared secret checked with `hash_equals` *before* any work.

## The ledger is a double-entry projection over the events

Each posting line is account/debit/credit in cents (`app/Domain/Ledger/`), rebuilt by replay — never a manual balance UPDATE. Each posting balances within a single currency (no cross-currency arithmetic, no rate); an external `fx_exchange` account closes the conversion legs. **Reconciliation asserts every intermediate holding nets to zero** — a cent stuck in any holding surfaces as `reconciliation.discrepancy`.

## The ledger is materialized into `ledger_entries` as an auditable read-model

A Spatie projector (`app/Projectors/LedgerEntryProjector.php`, registered explicitly in `config/event-sourcing.php`) writes the double-entry into a table. The event stream stays the source of truth; the table is a queryable projection, reconstructed by replay (`event-sourcing:replay` truncates and rebuilds it) and **never touched by an UPDATE**. The projector reuses the in-memory `LedgerProjector` for the posting rules rather than re-deriving them.

Deliberately, **reconciliation does not read this table** — it re-projects from the events independently. Trusting the projection to verify itself is circular; re-deriving from the source of truth is precisely what lets reconcile detect a drift in the projection.
