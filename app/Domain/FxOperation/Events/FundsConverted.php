<?php

declare(strict_types=1);

namespace App\Domain\FxOperation\Events;

use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Rate;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

final class FundsConverted extends ShouldBeStored
{
    public function __construct(
        public string $operationId,
        public Money $brlAmount,
        public Money $quotedUsd,
        public Money $executedUsdc,
        public Rate $executedRate,
        public string $orderRef,
    ) {}
}
