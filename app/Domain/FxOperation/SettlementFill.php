<?php

declare(strict_types=1);

namespace App\Domain\FxOperation;

use App\Domain\Shared\Money;

/** What the liquidity provider confirms for a completed off-ramp: USD in, delivered to destinationRef. */
final readonly class SettlementFill
{
    public function __construct(
        public Money $usd,
        public string $destinationRef,
    ) {}
}
