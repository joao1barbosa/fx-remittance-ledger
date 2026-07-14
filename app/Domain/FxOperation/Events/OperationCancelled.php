<?php

declare(strict_types=1);

namespace App\Domain\FxOperation\Events;

use App\Domain\FxOperation\CancellationReason;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

final class OperationCancelled extends ShouldBeStored
{
    public function __construct(
        public string $operationId,
        public CancellationReason $reason,
    ) {}
}
