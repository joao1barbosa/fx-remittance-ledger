# Scope & assumptions

What was cut on purpose, what is faked, and the assumptions I made deliberately. The point of an event-sourced design is that most of these are *additive later* — because the facts are already durable, adding a reaction is a new slice, not a migration or a rewrite. The [README](../README.md) carries the summary and the planned-vs-implemented diagrams; this is the reasoning behind each cut.

## Faked or cut on purpose

Everything outside the core guarantee is faked behind an interface or cut. Provider integrations are fakes behind ports (a BaaS deposit webhook, `FakeCryptoExchange`, `FakeLiquidity`, `FakeComplianceProvider`), so the pipeline is exercised end to end without a network. No UI — flows are driven by application handlers and the two webhook endpoints. BRL/PIX inbound rail only; no auth, no multi-tenancy, no retry/backoff machinery.

## Deferred infrastructure — additive because the facts are already durable

`settlement.failed` classification/retry and the refund router (below) are documented but unbuilt. The **reactors** that would push the pipeline forward on their own — compliance screening on `deposit.confirmed`, reconcile on `payout.completed` — are not wired; each handler is invoked directly. The materialized ledger (`ledger_entries`, see [Design](design.md)) has a read endpoint — `GET /operations/{id}/ledger` aggregates the persisted postings by account and reports the reconciliation verdict, all from the read-model (no replay, no aggregate retrieve): the query side of the ES/CQRS split. It carries no `currency` column — it is derivable from the account, so it is left for when a query needs it. Webhook hardening beyond the shared secret (HMAC-over-body, dedup/replay storage) is left out.

## Late deposit → cancel, not refund (a stated assumption)

`deposit.expired` cascades to `operation.cancelled`, not `refund.initiated`. This assumes the payment provider rejects or refunds a deposit that lands after the quote window *upstream*, so no customer funds are ever held on our side (call it "Mundo B"). On a raw-PIX rail ("Mundo A") a late webhook would mean funds already settled and irreversibly received — there the correct reaction is `refund.initiated`, since cancelling without returning the money would strand the customer's balance. I kept to the take-home's flow but flagged the assumption deliberately: because `deposit.expired` is a durable event, swapping the reaction to a refund reactor later is additive — no migration, no lost facts.

## Compliance screening runs synchronously here; production would trigger it from a reactor

Before conversion the operation passes the screening gate — identity, sanctions, PEP, adverse media. In this slice screening is an explicit command on the aggregate: the identity provider is called in the application handler and its verdict is passed into the pure aggregate as data, which records `compliance.approved` (the gate opens) or `compliance.review_required` (the operation pauses — a match never proceeds on its own). Conversion refuses unless the operation is approved. This keeps a single consistency boundary and makes the gate trivially testable.

**Two deliberate simplifications, both additive later.** (1) In production the screening call belongs in a reactor on `deposit.confirmed` — a genuinely external, latency-bound service — so deposit confirmation never blocks on a third party; here it is driven synchronously. (2) `compliance.review_required` only sets the status; the manual-review resolution is not implemented. When a human resolves a review it yields either `approved` (proceed) or `rejected`. Because the aggregate owns the same events and the same conversion invariant either way, adding the reactor and the review resolution later is additive — no new migration, no lost facts.

## Refund is a shared reaction with three triggers, all deferred

Once the deposit is confirmed the customer's BRL is held, so any downstream terminal failure must return it: `compliance.rejected` (a review resolved against the customer), `conversion.failed` (the exchange order could not be filled — liquidity, timeout, rejection), and `settlement.failed` (the off-ramp failed terminally) all drive `refund.initiated`. That is a single router that, on the event, dispatches to the correct provider's refund worker (per-provider, since each rail refunds differently). All three triggers are durable facts, so the refund router is additive later — the slices record the successful paths and the alerts now; the failure facts and their refund reaction are a follow-up slice.

## Confirmation is terminal for the deposit step — it wins over the window

Once a deposit is confirmed, the operation stops watching the clock: a *second* deposit is refused as "already confirmed" rather than re-evaluated against the window, even if it arrives after `expiresAt`. This is a deliberate guard ordering inside `confirmDeposit` — the idempotency check (same `providerRef` → no new fact) and the single-deposit check (different `providerRef` → refuse) both run *before* the late-window check. So a confirmed operation can never be retroactively expired by a straggler; the window only governs the *first* deposit. The alternative — letting a late straggler expire an already-confirmed operation — would let a duplicate webhook unwind a settled deposit, which is exactly the kind of lost fact event sourcing exists to prevent.
