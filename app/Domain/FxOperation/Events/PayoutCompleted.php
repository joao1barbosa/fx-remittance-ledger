<?php

declare(strict_types=1);

namespace App\Domain\FxOperation\Events;

use App\Domain\Shared\ValueObjects\Money;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/** The settled USD was delivered to the beneficiary. */
final class PayoutCompleted extends ShouldBeStored
{
    public function __construct(
        public string $operationId,
        public Money $usdAmount,
        public string $destinationRef,
    ) {}
}
