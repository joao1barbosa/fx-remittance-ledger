<?php

declare(strict_types=1);

namespace App\Domain\FxOperation\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/** The ledger balances — every intermediate holding zeroed; the operation closes. */
final class OperationReconciled extends ShouldBeStored
{
    public function __construct(
        public string $operationId,
    ) {}
}
