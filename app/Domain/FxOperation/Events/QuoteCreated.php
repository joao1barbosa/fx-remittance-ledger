<?php

declare(strict_types=1);

namespace App\Domain\FxOperation\Events;

use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Rate;
use DateTimeImmutable;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

final class QuoteCreated extends ShouldBeStored
{
    public function __construct(
        public string $operationId,
        public Money $brlAmount,
        public Rate $rate,
        public int $spreadBps,
        public int $taxesBps,
        public Money $quotedUsd,
        public DateTimeImmutable $expiresAt,
    ) {}
}
