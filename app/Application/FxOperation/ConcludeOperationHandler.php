<?php

declare(strict_types=1);

namespace App\Application\FxOperation;

use App\Domain\FxOperation\FxOperation;
use App\Domain\Ledger\LedgerProjector;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * Call-site for reconciliation: replays the operation's events through the
 * double-entry ledger, computes whether the books close, and hands that verdict
 * to the pure aggregate as data — the projection read stays out of the aggregate.
 */
final class ConcludeOperationHandler
{
    public function handle(string $operationId): void
    {
        $events = EloquentStoredEvent::query()
            ->where('aggregate_uuid', $operationId)
            ->orderBy('id')
            ->get()
            ->map(fn (EloquentStoredEvent $stored) => $stored->toStoredEvent()->event);

        $balanced = (new LedgerProjector)->project($events)->holdingsBalanced();

        FxOperation::retrieve($operationId)
            ->reconcile($balanced)
            ->persist();
    }
}
