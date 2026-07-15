<?php

declare(strict_types=1);

namespace App\Domain\FxOperation\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/** The books did not close — value is stuck in a holding. Holds/alerts, does not reconcile. */
final class ReconciliationDiscrepancy extends ShouldBeStored
{
    public function __construct(
        public string $operationId,
    ) {}
}
