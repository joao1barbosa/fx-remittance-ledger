<?php

declare(strict_types=1);

namespace App\Infrastructure\Exchange;

use App\Domain\Shared\ValueObjects\ConversionFill;
use App\Domain\FxOperation\Ports\CryptoExchange;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Rate;

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
