<?php

declare(strict_types=1);

namespace App\Domain\FxOperation;

use App\Domain\Shared\Money;
use App\Domain\Shared\Rate;

/** What the crypto exchange returns for an executed order. */
final readonly class ConversionFill
{
    public function __construct(
        public Money $usdc,
        public Rate $executedRate,
        public string $orderRef,
    ) {}
}
