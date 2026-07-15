<?php

declare(strict_types=1);

namespace App\Projectors;

use App\Domain\FxOperation\Events\DepositConfirmed;
use App\Domain\FxOperation\Events\FundsConverted;
use App\Domain\FxOperation\Events\PayoutCompleted;
use App\Domain\FxOperation\Events\SettlementCompleted;
use App\Domain\Ledger\LedgerProjector;
use App\Models\LedgerEntry;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * Materializes the double-entry ledger into `ledger_entries` as an auditable read-model.
 *
 * It does NOT re-implement the accounting: on every movement event it reloads that
 * operation's stream and reuses the in-memory domain LedgerProjector (the single source
 * of the posting rules), then replaces that operation's rows (delete-by-aggregate + insert).
 * Runs on replay because it is a projection; a reactor (side effects) does not.
 *
 * The delete+insert per operation is idempotent, so replay is safe by construction.
 */
final class LedgerEntryProjector extends Projector
{
    // Each movement event triggers a full re-projection of its operation's stream.
    public function onDepositConfirmed(DepositConfirmed $event): void
    {
        $this->reproject($event->operationId);
    }

    public function onFundsConverted(FundsConverted $event): void
    {
        $this->reproject($event->operationId);
    }

    public function onSettlementCompleted(SettlementCompleted $event): void
    {
        $this->reproject($event->operationId);
    }

    public function onPayoutCompleted(PayoutCompleted $event): void
    {
        $this->reproject($event->operationId);
    }

    // Truncate on a full replay; scope to one operation when the replay is targeted.
    public function resetState(?string $aggregateUuid = null): void
    {
        LedgerEntry::query()
            ->when($aggregateUuid, fn ($q) => $q->where('aggregate_uuid', $aggregateUuid))
            ->delete();
    }

    private function reproject(string $operationId): void
    {
        $events = EloquentStoredEvent::query()
            ->where('aggregate_uuid', $operationId)
            ->orderBy('id')
            ->get()
            ->map(fn (EloquentStoredEvent $stored) => $stored->toStoredEvent()->event);

        $lines = (new LedgerProjector)->project($events)->lines();

        LedgerEntry::query()->where('aggregate_uuid', $operationId)->delete();

        LedgerEntry::query()->insert(array_map(fn ($line) => [
            'aggregate_uuid' => $operationId,
            'account' => $line->account->value,
            'debit' => $line->debit,
            'credit' => $line->credit,
        ], $lines));
    }
}
