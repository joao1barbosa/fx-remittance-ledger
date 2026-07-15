<?php

use App\Domain\Shared\ValueObjects\ConversionFill;
use App\Domain\FxOperation\Events\ComplianceApproved;
use App\Domain\FxOperation\Events\ConversionSlippageExceeded;
use App\Domain\FxOperation\Events\FundsConverted;
use App\Domain\FxOperation\FxOperation;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Rate;

function approvedOperation(): array
{
    return [...confirmedDeposit(), new ComplianceApproved(operationId: 'op-123')];
}

it('converts approved funds at the quoted price', function () {
    FxOperation::fake('op-123')
        ->given(approvedOperation())
        ->when(fn (FxOperation $op) => $op->convert(new ConversionFill(
            usdc: new Money(22753, Currency::USDC),
            executedRate: new Rate(1900),
            orderRef: 'ord-1',
        )))
        ->assertRecorded(new FundsConverted(
            operationId: 'op-123',
            brlAmount: new Money(123456, Currency::BRL),
            quotedUsd: new Money(22753, Currency::USD),
            executedUsdc: new Money(22753, Currency::USDC),
            executedRate: new Rate(1900),
            orderRef: 'ord-1',
        ))
        ->assertNotRecorded(ConversionSlippageExceeded::class);
});

it('refuses to convert before compliance is approved', function () {
    $fake = FxOperation::fake('op-123')->given(confirmedDeposit());

    expect(fn () => $fake->when(fn (FxOperation $op) => $op->convert(new ConversionFill(
        usdc: new Money(22753, Currency::USDC),
        executedRate: new Rate(1900),
        orderRef: 'ord-1',
    ))))->toThrow(DomainException::class);

    $fake->assertNothingRecorded();
});

it('flags slippage beyond tolerance', function () {
    FxOperation::fake('op-123')
        ->given(approvedOperation())
        ->when(fn (FxOperation $op) => $op->convert(new ConversionFill(
            usdc: new Money(22600, Currency::USDC),
            executedRate: new Rate(1887),
            orderRef: 'ord-2',
        )))
        ->assertRecorded([
            new FundsConverted(
                operationId: 'op-123',
                brlAmount: new Money(123456, Currency::BRL),
                quotedUsd: new Money(22753, Currency::USD),
                executedUsdc: new Money(22600, Currency::USDC),
                executedRate: new Rate(1887),
                orderRef: 'ord-2',
            ),
            new ConversionSlippageExceeded(
                operationId: 'op-123',
                quotedUsd: new Money(22753, Currency::USD),
                executedUsdc: new Money(22600, Currency::USDC),
                slippageBps: 67,
            ),
        ]);
});
