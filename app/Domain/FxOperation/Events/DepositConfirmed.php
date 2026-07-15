<?php

declare(strict_types=1);

namespace App\Domain\FxOperation\Events;

use App\Domain\Shared\Enums\DepositProvider;
use App\Domain\Shared\ValueObjects\Money;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

final class DepositConfirmed extends ShouldBeStored
{
    public function __construct(
        public string $operationId,
        public DepositProvider $provider,
        public Money $brlAmount,
        public string $providerRef,
    ) {}
}
