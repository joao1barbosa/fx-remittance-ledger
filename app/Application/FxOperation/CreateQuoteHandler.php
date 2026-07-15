<?php

declare(strict_types=1);

namespace App\Application\FxOperation;

use App\Domain\FxOperation\Ports\ExchangeRateProvider;
use App\Domain\FxOperation\FxOperation;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use DateTimeImmutable;

/**
 * Call-site for the quote: reads the raw mid-market rate from the provider,
 * then hands it to the pure aggregate as data. The aggregate never touches
 * the provider — dependency inversion keeps I/O at the edge.
 */
final class CreateQuoteHandler
{
    public function __construct(private ExchangeRateProvider $rates) {}

    public function handle(
        string $operationId,
        Money $brlAmount,
        int $spreadBps,
        int $taxesBps,
        DateTimeImmutable $at,
    ): void {
        $rate = $this->rates->rateFor(Currency::BRL, Currency::USD);

        FxOperation::retrieve($operationId)
            ->createQuote($operationId, $brlAmount, $rate, $spreadBps, $taxesBps, $at)
            ->persist();
    }
}
