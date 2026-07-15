<?php

declare(strict_types=1);

namespace App\Domain\FxOperation;

use App\Domain\Shared\Money;

/**
 * Outbound port: execute the BRL->USDC order on the crypto exchange and return
 * the fill. Distinct from ExchangeRateProvider, which only quotes a rate.
 */
interface CryptoExchange
{
    public function execute(Money $brl): ConversionFill;
}
