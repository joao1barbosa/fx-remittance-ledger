<?php

declare(strict_types=1);

namespace App\Infrastructure\Exchange;

use App\Domain\FxOperation\ConversionFill;
use App\Domain\FxOperation\CryptoExchange;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use App\Domain\Shared\Rate;

/** Returns a preset fill for tests and the slice; defaults to a deterministic one. No I/O. */
final class FakeCryptoExchange implements CryptoExchange
{
    public function __construct(
        private ConversionFill $fill = new ConversionFill(
            usdc: new Money(19400, Currency::USDC),
            executedRate: new Rate(2000),
            orderRef: 'fake-ord-1',
        ),
    ) {}

    public function execute(Money $brl): ConversionFill
    {
        return $this->fill;
    }
}
