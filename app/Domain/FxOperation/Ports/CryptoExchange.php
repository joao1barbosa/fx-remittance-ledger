<?php

declare(strict_types=1);

namespace App\Domain\FxOperation\Ports;

use App\Domain\Shared\ValueObjects\ConversionFill;
use App\Domain\Shared\ValueObjects\Money;

/**
 * Outbound port: execute the BRL->USDC order on the crypto exchange and return
 * the fill. Distinct from ExchangeRateProvider, which only quotes a rate.
 */
interface CryptoExchange
{
    public function execute(Money $brl): ConversionFill;
}
