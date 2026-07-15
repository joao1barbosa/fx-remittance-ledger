<?php

declare(strict_types=1);

namespace App\Domain\FxOperation\Events;

use App\Domain\Shared\ValueObjects\Money;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/** The USDC->USD off-ramp order was opened; awaiting the liquidity provider's fill. */
final class SettlementInitiated extends ShouldBeStored
{
    public function __construct(
        public string $operationId,
        public Money $usdcAmount,
        public string $orderRef,
    ) {}
}
