<?php

declare(strict_types=1);

namespace App\Domain\FxOperation\Events;

use App\Domain\Shared\ValueObjects\Money;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

final class ConversionSlippageExceeded extends ShouldBeStored
{
    public function __construct(
        public string $operationId,
        public Money $quotedUsd,
        public Money $executedUsdc,
        public int $slippageBps,
    ) {}
}
