<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Shared\Enums\LedgerAccount;
use App\Models\LedgerEntry;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read side of the ES/CQRS split: serves an operation's ledger straight from the
 * materialized `ledger_entries` read-model — no replay, no aggregate retrieve.
 * The reconcile command stays the independent event-derived check; if the two ever
 * disagreed, that gap is exactly the drift reconcile exists to catch.
 */
final class LedgerController
{
    public function show(string $operationId): JsonResponse
    {
        $rows = LedgerEntry::query()
            ->where('aggregate_uuid', $operationId)
            ->selectRaw('account, sum(debit) as debit, sum(credit) as credit')
            ->groupBy('account')
            ->get()
            ->keyBy('account');

        if ($rows->isEmpty()) {
            throw new NotFoundHttpException("No ledger for operation {$operationId}.");
        }

        $accounts = $rows->map(fn ($row) => [
            'debit' => (int) $row->debit,
            'credit' => (int) $row->credit,
            'net' => (int) $row->debit - (int) $row->credit,
        ]);

        // Balanced ⇔ every holding nets to zero — the "no cent lost" verdict, read
        // off the table. Reuses the domain rule instead of re-listing the accounts.
        $balanced = $accounts
            ->filter(fn ($_, $account) => LedgerAccount::from($account)->isHolding())
            ->every(fn ($a) => $a['net'] === 0);

        return response()->json([
            'operationId' => $operationId,
            'balanced' => $balanced,
            'accounts' => $accounts,
        ]);
    }
}
