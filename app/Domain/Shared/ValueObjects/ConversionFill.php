<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Rate;

/** What the crypto exchange returns for an executed order. */
final readonly class ConversionFill
{
    public function __construct(
        public Money $usdc,
        public Rate $executedRate,
        public string $orderRef,
    ) {}
}
