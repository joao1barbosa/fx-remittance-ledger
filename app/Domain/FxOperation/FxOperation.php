<?php

declare(strict_types=1);

namespace App\Domain\FxOperation;

use App\Domain\FxOperation\Events\QuoteCreated;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use App\Domain\Shared\Rate;
use DateTimeImmutable;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

final class FxOperation extends AggregateRoot
{
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
        // quotedUsdCents = round-half-up(brlCents * rateScaled * netBps / (SCALE * 10_000))
        // ponytail: integer-exact to ~46M BRL/op — the 2*N doubling below is the
        // binding int64 limit; switch to brick/math if larger amounts are ever needed.
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
}
