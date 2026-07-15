<?php

use App\Domain\FxOperation\Events\OperationReconciled;
use App\Domain\FxOperation\Events\PayoutCompleted;
use App\Domain\FxOperation\Events\ReconciliationDiscrepancy;
use App\Domain\FxOperation\Events\SettlementCompleted;
use App\Domain\FxOperation\FxOperation;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;

// A fully settled operation: converted, off-ramped, and paid out.
function settledOperation(): array
{
    return [...initiatedOperation(),
        new SettlementCompleted(
            operationId: 'op-123',
            usdAmount: new Money(22730, Currency::USD),
        ),
        new PayoutCompleted(
            operationId: 'op-123',
            usdAmount: new Money(22730, Currency::USD),
            destinationRef: 'ach-9',
        ),
    ];
}

it('reconciles an operation whose books balance', function () {
    FxOperation::fake('op-123')
        ->given(settledOperation())
        ->when(fn (FxOperation $op) => $op->reconcile(balanced: true))
        ->assertRecorded(new OperationReconciled(operationId: 'op-123'));
});

it('flags a discrepancy when the books do not balance', function () {
    // reconcile MUST be able to fail — that is what makes it reconciliation.
    FxOperation::fake('op-123')
        ->given(settledOperation())
        ->when(fn (FxOperation $op) => $op->reconcile(balanced: false))
        ->assertRecorded(new ReconciliationDiscrepancy(operationId: 'op-123'));
});

it('refuses to reconcile before the payout completed', function () {
    $fake = FxOperation::fake('op-123')->given(initiatedOperation());

    expect(fn () => $fake->when(fn (FxOperation $op) => $op->reconcile(balanced: true)))
        ->toThrow(DomainException::class);

    $fake->assertNothingRecorded();
});
