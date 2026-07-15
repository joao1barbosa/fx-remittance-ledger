<?php

declare(strict_types=1);

namespace App\Domain\FxOperation;

use App\Domain\FxOperation\Events\ComplianceApproved;
use App\Domain\FxOperation\Events\ComplianceReviewRequired;
use App\Domain\FxOperation\Events\ConversionSlippageExceeded;
use App\Domain\FxOperation\Events\DepositConfirmed;
use App\Domain\FxOperation\Events\DepositExpired;
use App\Domain\FxOperation\Events\FundsConverted;
use App\Domain\FxOperation\Events\OperationCancelled;
use App\Domain\FxOperation\Events\PayoutCompleted;
use App\Domain\FxOperation\Events\QuoteCreated;
use App\Domain\FxOperation\Events\SettlementCompleted;
use App\Domain\FxOperation\Events\SettlementInitiated;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use App\Domain\Shared\Rate;
use DateTimeImmutable;
use DomainException;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

final class FxOperation extends AggregateRoot
{
    /** 0.5% — tunable business policy. */
    private const SLIPPAGE_TOLERANCE_BPS = 50;

    /** The quoted amount, remembered so a deposit confirms what was quoted. */
    private ?Money $brlAmount = null;

    /** The quoted USD target; convert() compares the fill against it. */
    private ?Money $quotedUsd = null;

    /** End of the quote window; a deposit after this expires instead of confirming. */
    private ?DateTimeImmutable $expiresAt = null;

    /** The confirmed deposit's ref, if any — makes confirmDeposit idempotent. */
    private ?string $depositProviderRef = null;

    /** Once cancelled the operation is terminal; every command refuses. */
    private bool $cancelled = false;

    /** The screening verdict, null until screened; the future convert() reads it. */
    private ?ComplianceDecision $complianceDecision = null;

    /** The USDC obtained by convert(), null until then; sizes and gates the off-ramp. */
    private ?Money $executedUsdc = null;

    /** Once the off-ramp order is open, confirmSettlement may complete it. */
    private bool $settlementInitiated = false;

    /** Terminal once settled — a re-delivered webhook then no-ops, never pays twice. */
    private bool $settlementCompleted = false;
    /**
     * Price a remittance and open the quote window. Pure: rate, spread, taxes
     * and the current instant are passed in as data — the aggregate never
     * fetches a rate or reads the clock. All money math is integer.
     */
    public function createQuote(
        string $operationId,
        Money $brlAmount,
        Rate $rate,
        int $spreadBps,
        int $taxesBps,
        DateTimeImmutable $at,
    ): static {
        $this->assertNotCancelled();

        if ($brlAmount->currency !== Currency::BRL) {
            throw new DomainException("Quote must be in BRL; got {$brlAmount->currency->value}.");
        }
        if ($brlAmount->cents <= 0) {
            throw new DomainException('Quote amount must be positive.');
        }
        if ($spreadBps < 0 || $taxesBps < 0) {
            throw new DomainException("Spread and taxes cannot be negative; got {$spreadBps}/{$taxesBps} bps.");
        }
        if ($spreadBps + $taxesBps >= 10_000) {
            throw new DomainException("Spread plus taxes must be below 100%; got {$spreadBps}+{$taxesBps} bps.");
        }

        // quotedUsdCents = round-half-up(brlCents * rateScaled * netBps / (SCALE * 10_000))
        // integer-exact to ~46M BRL/op — the 2*N doubling below is the binding int64 limit;
        // switch to brick/math if larger amounts are ever needed.
        $netBps = 10_000 - $spreadBps - $taxesBps;
        $n = $brlAmount->cents * $rate->scaled * $netBps;
        $d = Rate::SCALE * 10_000;
        $quotedUsd = new Money(intdiv(2 * $n + $d, 2 * $d), Currency::USD);

        $this->recordThat(new QuoteCreated(
            operationId: $operationId,
            brlAmount: $brlAmount,
            rate: $rate,
            spreadBps: $spreadBps,
            taxesBps: $taxesBps,
            quotedUsd: $quotedUsd,
            expiresAt: $at->modify('+15 minutes'),
        ));

        return $this;
    }

    protected function applyQuoteCreated(QuoteCreated $event): void
    {
        $this->brlAmount = $event->brlAmount;
        $this->quotedUsd = $event->quotedUsd;
        $this->expiresAt = $event->expiresAt;
    }

    protected function applyDepositConfirmed(DepositConfirmed $event): void
    {
        $this->depositProviderRef = $event->providerRef;
    }

    protected function applyOperationCancelled(OperationCancelled $event): void
    {
        $this->cancelled = true;
    }

    private function assertNotCancelled(): void
    {
        if ($this->cancelled) {
            throw new DomainException('Operation is cancelled and accepts no further commands.');
        }
    }

    /**
     * Confirm the incoming PIX deposit against the open quote. The deposit
     * confirms the amount that was quoted, so brlAmount comes from replayed
     * state, not the caller.
     */
    public function confirmDeposit(
        DepositProvider $provider,
        string $providerRef,
        DateTimeImmutable $at,
    ): static {
        $this->assertNotCancelled();

        if ($this->expiresAt === null || $this->brlAmount === null) {
            throw new DomainException('Cannot confirm a deposit on an operation without an open quote.');
        }
        if (trim($providerRef) === '') {
            throw new DomainException('Deposit providerRef is required.');
        }
        if ($this->depositProviderRef === $providerRef) {
            // Same deposit reported twice — one effect, no new fact. Observing a
            // flood of re-deliveries is the webhook handler's job (warn/alert there).
            return $this;
        }
        if ($this->depositProviderRef !== null) {
            throw new DomainException('Operation already has a confirmed deposit.');
        }
        if ($at > $this->expiresAt) {
            // A deposit past the window cascades: the late arrival is a fact, and
            // its consequence is cancellation. We assume the PSP rejects/refunds
            // late deposits upstream, so no funds are held here, hence cancel, not refund.
            // On a raw-PIX rail a late webhook would mean settled funds, and this branch
            // would instead emit refund.initiated.

            // See README "What was left out and why".
            $this->recordThat(new DepositExpired(operationId: $this->uuid()));
            $this->recordThat(new OperationCancelled(
                operationId: $this->uuid(),
                reason: CancellationReason::DepositWindowElapsed,
            ));

            return $this;
        }

        $this->recordThat(new DepositConfirmed(
            operationId: $this->uuid(),
            provider: $provider,
            brlAmount: $this->brlAmount,
            providerRef: $providerRef,
        ));

        return $this;
    }

    /**
     * Triage the compliance screening verdict. The provider is called in the
     * application handler; its decision arrives here as data (same seam as the
     * rate). Screening runs against a confirmed deposit, so it refuses before one.
     */
    public function screenCompliance(
        ComplianceDecision $decision,
    ): static {
        $this->assertNotCancelled();

        if ($this->depositProviderRef === null) {
            throw new DomainException('Cannot screen compliance before a deposit is confirmed.');
        }
        if ($this->complianceDecision !== null) {
            throw new DomainException('Operation was already screened for compliance.');
        }

        // A match pauses the pipeline for a human — it does not cancel. The
        // review resolution (approve/reject → refund) is a later slice.
        $this->recordThat(match ($decision) {
            ComplianceDecision::Approved => new ComplianceApproved(operationId: $this->uuid()),
            ComplianceDecision::ReviewRequired => new ComplianceReviewRequired(operationId: $this->uuid()),
        });

        return $this;
    }

    protected function applyComplianceApproved(ComplianceApproved $event): void
    {
        $this->complianceDecision = ComplianceDecision::Approved;
    }

    protected function applyComplianceReviewRequired(ComplianceReviewRequired $event): void
    {
        $this->complianceDecision = ComplianceDecision::ReviewRequired;
    }

    /** The BRL to convert; the ConvertHandler reads it to size the exchange order. */
    public function brlAmount(): ?Money
    {
        return $this->brlAmount;
    }

    /**
     * Execute the conversion into USDC. Fail-closed gate: only an Approved
     * decision proceeds; null (never screened) and ReviewRequired both refuse.
     * The exchange fill arrives as data — the aggregate performs no I/O.
     */
    public function convert(ConversionFill $fill): static
    {
        if ($this->complianceDecision !== ComplianceDecision::Approved) {
            throw new DomainException('Cannot convert before compliance is approved.');
        }

        // USDC is 1:1 with USD and the off-ramp fee lands in settlement, so the
        // quoted USD target is the USDC target; slippage is measured in cents.
        $slippageBps = intdiv(
            abs($fill->usdc->cents - $this->quotedUsd->cents) * 10_000,
            $this->quotedUsd->cents,
        );

        $this->recordThat(new FundsConverted(
            operationId: $this->uuid(),
            brlAmount: $this->brlAmount,
            quotedUsd: $this->quotedUsd,
            executedUsdc: $fill->usdc,
            executedRate: $fill->executedRate,
            orderRef: $fill->orderRef,
        ));

        // Out of tolerance alerts but does not halt: the customer still gets the
        // locked quoted amount; the excess is a durable fact for reconciliation.
        if ($slippageBps > self::SLIPPAGE_TOLERANCE_BPS) {
            $this->recordThat(new ConversionSlippageExceeded(
                operationId: $this->uuid(),
                quotedUsd: $this->quotedUsd,
                executedUsdc: $fill->usdc,
                slippageBps: $slippageBps,
            ));
        }

        return $this;
    }

    protected function applyFundsConverted(FundsConverted $event): void
    {
        $this->executedUsdc = $event->executedUsdc;
    }

    /** The USDC available to off-ramp; the InitiateSettlementHandler sizes the order. */
    public function usdcAmount(): ?Money
    {
        return $this->executedUsdc;
    }

    /**
     * Open the USDC->USD off-ramp order. Async saga, outbound leg: the provider
     * order ref arrives as data; the completing fill lands later via webhook.
     * Gate mirrors convert's — only after funds are converted (executedUsdc set).
     */
    public function initiateSettlement(string $orderRef): static
    {
        $this->assertNotCancelled();

        if ($this->executedUsdc === null) {
            throw new DomainException('Cannot initiate settlement before funds are converted.');
        }

        $this->recordThat(new SettlementInitiated(
            operationId: $this->uuid(),
            usdcAmount: $this->executedUsdc,
            orderRef: $orderRef,
        ));

        return $this;
    }

    protected function applySettlementInitiated(SettlementInitiated $event): void
    {
        $this->settlementInitiated = true;
    }

    protected function applySettlementCompleted(SettlementCompleted $event): void
    {
        $this->settlementCompleted = true;
    }

    /**
     * Complete the off-ramp: one provider webhook, two facts. USD landed in the
     * settlement account (the elided off-ramp fee lands here, so usd <= usdc),
     * then that USD was delivered to the beneficiary — separate ledger legs, so
     * separate events. Gate: only after the order was initiated.
     */
    public function confirmSettlement(SettlementFill $fill): static
    {
        $this->assertNotCancelled();

        if (!$this->settlementInitiated) {
            throw new DomainException('Cannot confirm settlement before it was initiated.');
        }
        if ($this->settlementCompleted) {
            // Re-delivered webhook against an already-settled operation — one effect,
            // no second payout. Mirrors confirmDeposit's same-ref idempotent no-op.
            return $this;
        }

        $this->recordThat(new SettlementCompleted(
            operationId: $this->uuid(),
            usdAmount: $fill->usd,
        ));
        $this->recordThat(new PayoutCompleted(
            operationId: $this->uuid(),
            usdAmount: $fill->usd,
            destinationRef: $fill->destinationRef,
        ));

        return $this;
    }
}
