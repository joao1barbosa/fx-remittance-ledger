<?php

use App\Domain\FxOperation\FxOperation;
use App\Domain\Shared\Enums\ComplianceDecision;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\DepositProvider;
use App\Domain\Shared\ValueObjects\ConversionFill;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Rate;
use App\Domain\Shared\ValueObjects\SettlementFill;
use Illuminate\Support\Str;

// Drives the full happy path so the LedgerEntryProjector materializes the ledger.
// BRL 1,000.00 -> USD 194.00 (zero slippage), off-ramps to USD 193.80 (20c fee).
function settleOperation(): string
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

it('serves an operation ledger from the materialized read-model', function () {
    $uuid = settleOperation();

    $this->getJson("/operations/{$uuid}/ledger")
        ->assertOk()
        ->assertJsonPath('operationId', $uuid)
        ->assertJsonPath('balanced', true)
        // net = debit - credit, in cents; holdings zero, terminals carry the outcome.
        ->assertJsonPath('accounts.brl_holding.net', 0)
        ->assertJsonPath('accounts.customer.net', -100_000)
        ->assertJsonPath('accounts.beneficiary.net', 19_380)
        ->assertJsonPath('accounts.fees.net', 20);
});

it('returns 404 for an operation with no ledger', function () {
    $this->getJson('/operations/'.Str::uuid().'/ledger')->assertNotFound();
});
