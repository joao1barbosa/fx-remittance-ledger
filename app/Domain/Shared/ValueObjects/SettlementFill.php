<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\ValueObjects\Money;

/** What the liquidity provider confirms for a completed off-ramp: USD in, delivered to destinationRef. */
final readonly class SettlementFill
{
    public function __construct(
        public Money $usd,
        public string $destinationRef,
    ) {}
}
