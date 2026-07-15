<?php

declare(strict_types=1);

namespace App\Domain\FxOperation\Events;

use App\Domain\Shared\Money;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/** USD landed in the settlement account; the elided off-ramp fee is in usdc - usd. */
final class SettlementCompleted extends ShouldBeStored
{
    public function __construct(
        public string $operationId,
        public Money $usdAmount,
    ) {}
}
