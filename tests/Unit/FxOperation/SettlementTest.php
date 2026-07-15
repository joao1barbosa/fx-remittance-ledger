<?php

use App\Domain\FxOperation\Events\FundsConverted;
use App\Domain\FxOperation\Events\PayoutCompleted;
use App\Domain\FxOperation\Events\SettlementCompleted;
use App\Domain\FxOperation\Events\SettlementInitiated;
use App\Domain\FxOperation\FxOperation;
use App\Domain\FxOperation\SettlementFill;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use App\Domain\Shared\Rate;

// An operation whose funds are already converted (approved + FundsConverted).
function convertedOperation(): array
{
    return [...approvedOperation(), new FundsConverted(
        operationId: 'op-123',
        brlAmount: new Money(123456, Currency::BRL),
        quotedUsd: new Money(22753, Currency::USD),
        executedUsdc: new Money(22753, Currency::USDC),
        executedRate: new Rate(1900),
        orderRef: 'ord-1',
    )];
}

// The off-ramp order is open and pending confirmation.
function initiatedOperation(): array
{
    return [...convertedOperation(), new SettlementInitiated(
        operationId: 'op-123',
        usdcAmount: new Money(22753, Currency::USDC),
        orderRef: 'sett-1',
    )];
}

it('initiates the off-ramp once funds are converted', function () {
    FxOperation::fake('op-123')
        ->given(convertedOperation())
        ->when(fn (FxOperation $op) => $op->initiateSettlement('sett-1'))
        ->assertRecorded(new SettlementInitiated(
            operationId: 'op-123',
            usdcAmount: new Money(22753, Currency::USDC),
            orderRef: 'sett-1',
        ));
});

it('refuses to initiate settlement before funds are converted', function () {
    $fake = FxOperation::fake('op-123')->given(approvedOperation());

    expect(fn () => $fake->when(fn (FxOperation $op) => $op->initiateSettlement('sett-1')))
        ->toThrow(DomainException::class);

    $fake->assertNothingRecorded();
});

it('completes settlement and payout as two facts from one provider webhook', function () {
    // The USD (22730) is below the USDC (22753) — the off-ramp fee lands here.
    FxOperation::fake('op-123')
        ->given(initiatedOperation())
        ->when(fn (FxOperation $op) => $op->confirmSettlement(new SettlementFill(
            usd: new Money(22730, Currency::USD),
            destinationRef: 'ach-9',
        )))
        ->assertRecorded([
            new SettlementCompleted(
                operationId: 'op-123',
                usdAmount: new Money(22730, Currency::USD),
            ),
            new PayoutCompleted(
                operationId: 'op-123',
                usdAmount: new Money(22730, Currency::USD),
                destinationRef: 'ach-9',
            ),
        ]);
});

it('refuses to confirm settlement before it was initiated', function () {
    $fake = FxOperation::fake('op-123')->given(convertedOperation());

    expect(fn () => $fake->when(fn (FxOperation $op) => $op->confirmSettlement(new SettlementFill(
        usd: new Money(22730, Currency::USD),
        destinationRef: 'ach-9',
    ))))->toThrow(DomainException::class);

    $fake->assertNothingRecorded();
});
