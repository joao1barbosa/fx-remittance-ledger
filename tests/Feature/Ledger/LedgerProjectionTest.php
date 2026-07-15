<?php

use App\Domain\FxOperation\ComplianceDecision;
use App\Domain\FxOperation\ConversionFill;
use App\Domain\FxOperation\DepositProvider;
use App\Domain\FxOperation\FxOperation;
use App\Domain\FxOperation\SettlementFill;
use App\Domain\Ledger\LedgerAccount;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use App\Domain\Shared\Rate;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

// The whole happy path, driven through the real commands. BRL 1,000.00 quotes to
// USD 194.00 (zero slippage), off-ramps to USD 193.80 — a 20c fee.
function runHappyPath(): string
{
    $uuid = (string) Str::uuid();
    $at = new DateTimeImmutable('2026-07-14 12:00:00');

    FxOperation::retrieve($uuid)
        ->createQuote($uuid, new Money(100_000, Currency::BRL), new Rate(2000), 200, 100, $at)
        ->confirmDeposit(DepositProvider::FAKE_BANK, 'pix-abc', $at->modify('+5 minutes'))
        ->screenCompliance(ComplianceDecision::Approved)
        ->convert(new ConversionFill(new Money(19_400, Currency::USDC), new Rate(2000), 'ord-1'))
        ->initiateSettlement('sett-1')
        ->confirmSettlement(new SettlementFill(new Money(19_380, Currency::USD), 'ach-9'))
        ->persist();

    return $uuid;
}

function ledgerNet(string $uuid, LedgerAccount $account): int
{
    $lines = LedgerEntry::query()
        ->where('aggregate_uuid', $uuid)
        ->where('account', $account->value);

    return (int) (clone $lines)->sum('debit') - (int) (clone $lines)->sum('credit');
}

it('materializes a balanced double-entry ledger as the operation runs', function () {
    $uuid = runHappyPath();

    // Every intermediate holding zeroes out in the persisted table — no cent stuck.
    expect(ledgerNet($uuid, LedgerAccount::BrlHolding))->toBe(0)
        ->and(ledgerNet($uuid, LedgerAccount::UsdcHolding))->toBe(0)
        ->and(ledgerNet($uuid, LedgerAccount::UsdHolding))->toBe(0);

    // Terminals carry the outcome: customer paid BRL 1,000.00, beneficiary got
    // USD 193.80, the house kept the USD 0.20 off-ramp fee.
    expect(ledgerNet($uuid, LedgerAccount::Customer))->toBe(-100_000)
        ->and(ledgerNet($uuid, LedgerAccount::Beneficiary))->toBe(19_380)
        ->and(ledgerNet($uuid, LedgerAccount::Fees))->toBe(20);
});

it('rebuilds the identical ledger from an event replay', function () {
    $uuid = runHappyPath();

    $before = LedgerEntry::query()
        ->orderBy('id')
        ->get(['aggregate_uuid', 'account', 'debit', 'credit'])
        ->toArray();

    // A projection is recalculable: wipe the table and replay the stream from the
    // stored events — the same postings come back.
    LedgerEntry::query()->delete();
    expect(LedgerEntry::query()->count())->toBe(0);

    Artisan::call('event-sourcing:replay');

    $after = LedgerEntry::query()
        ->orderBy('id')
        ->get(['aggregate_uuid', 'account', 'debit', 'credit'])
        ->toArray();

    expect($after)->toEqual($before);
});
