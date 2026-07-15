<?php

declare(strict_types=1);

namespace App\Infrastructure\Exchange;

use App\Domain\FxOperation\Ports\ExchangeRateProvider;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Rate;

/**
 * Deterministic rate for tests and the vertical slice: 1 USD = 5 BRL,
 * i.e. 0.20 USD per BRL -> 2000 scaled. No I/O.
 */
final class FakeExchangeRateProvider implements ExchangeRateProvider
{
    public function rateFor(Currency $from, Currency $to): Rate
    {
        return new Rate(2000);
    }
}
