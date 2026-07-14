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

## How this was built

I used an AI coding agent as a pair, deliberately guarded by tests. The split was explicit: I own
the specs — the failing tests that encode each domain invariant — and the architectural decisions
(the event catalog, money as integer cents, choosing Laravel 13 over an advisory-blocked 11); the
agent implements against those red tests and I refactor. Test-first on the money and correctness
core is what keeps AI-written code honest; scaffolding and boilerplate were delegated. The commit
history shows the red→green rhythm.

_TODO (Wed): tighten once the slice is done — keep it to a few confident sentences._
