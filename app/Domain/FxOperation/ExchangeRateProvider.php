<?php

declare(strict_types=1);

namespace App\Domain\FxOperation;

use App\Domain\Shared\Currency;
use App\Domain\Shared\Rate;

/**
 * Port for the raw mid-market rate only (amount of `to` per 1 unit of `from`).
 * Spread and taxes are our pricing domain, applied outside the provider.
 * A real HTTP adapter parses a decimal string into a scaled Rate later.
 */
interface ExchangeRateProvider
{
    public function rateFor(Currency $from, Currency $to): Rate;
}
