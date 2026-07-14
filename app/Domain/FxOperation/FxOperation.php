<?php

declare(strict_types=1);

namespace App\Domain\FxOperation;

use App\Domain\FxOperation\Events\DepositConfirmed;
use App\Domain\FxOperation\Events\DepositExpired;
use App\Domain\FxOperation\Events\QuoteCreated;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use App\Domain\Shared\Rate;
use DateTimeImmutable;
use DomainException;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

final class FxOperation extends AggregateRoot
{
    /** The quoted amount, remembered so a deposit confirms what was quoted. */
    private ?Money $brlAmount = null;

    /** End of the quote window; a deposit after this expires instead of confirming. */
    private ?DateTimeImmutable $expiresAt = null;

    /** The confirmed deposit's ref, if any — makes confirmDeposit idempotent. */
    private ?string $depositProviderRef = null;
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
        $this->expiresAt = $event->expiresAt;
    }

    protected function applyDepositConfirmed(DepositConfirmed $event): void
    {
        $this->depositProviderRef = $event->providerRef;
    }

    /**
     * Confirm the incoming PIX deposit against the open quote. The deposit
     * confirms the amount that was quoted, so brlAmount comes from replayed
     * state — not the caller. Pure: the instant is passed in.
     */
    public function confirmDeposit(
        DepositProvider $provider,
        string $providerRef,
        DateTimeImmutable $at,
    ): static {
        if ($this->expiresAt === null || $this->brlAmount === null) {
            throw new DomainException('Cannot confirm a deposit on an operation without an open quote.');
        }
        if (trim($providerRef) === '') {
            throw new DomainException('Deposit providerRef is required.');
        }
        if ($this->depositProviderRef === $providerRef) {
            // Same deposit reported twice — one effect, no new fact. Observing a
            // flood of re-deliveries is the webhook handler's job (warn/alert
            // there), not the aggregate's: it must stay pure and replay-safe.
            // ponytail: handler logs the no-op when we build the idempotent webhook.
            return $this;
        }
        if ($at > $this->expiresAt) {
            $this->recordThat(new DepositExpired(operationId: $this->uuid()));

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
}
