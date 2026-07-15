<?php

use App\Domain\Shared\Enums\CancellationReason;
use App\Domain\Shared\Enums\DepositProvider;
use App\Domain\FxOperation\Events\DepositConfirmed;
use App\Domain\FxOperation\Events\DepositExpired;
use App\Domain\FxOperation\Events\OperationCancelled;
use App\Domain\FxOperation\Events\QuoteCreated;
use App\Domain\FxOperation\FxOperation;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Rate;

// A quote already on the books, opening a 15-min window at 12:00.
function openQuote(): array
{
    $at = new DateTimeImmutable('2026-07-14 12:00:00');

    return [new QuoteCreated(
        operationId: 'op-123',
        brlAmount: new Money(123456, Currency::BRL),
        rate: new Rate(1900),
        spreadBps: 200,
        taxesBps: 100,
        quotedUsd: new Money(22753, Currency::USD),
        expiresAt: $at->modify('+15 minutes'),   // 12:15
    )];
}

it('confirms a deposit that arrives inside the quote window', function () {
    FxOperation::fake('op-123')
        ->given(openQuote())
        ->when(fn (FxOperation $op) => $op->confirmDeposit(
            provider: DepositProvider::FAKE_BANK,
            providerRef: 'pix-abc',
            at: new DateTimeImmutable('2026-07-14 12:05:00'),   // inside window
        ))
        ->assertRecorded(new DepositConfirmed(
            operationId: 'op-123',
            provider: DepositProvider::FAKE_BANK,
            brlAmount: new Money(123456, Currency::BRL),
            providerRef: 'pix-abc',
        ));
});

it('refuses a deposit on an operation that was never quoted', function () {
    $fake = FxOperation::fake('op-sem-quote');

    expect(fn () => $fake->when(fn (FxOperation $op) => $op->confirmDeposit(
        provider: DepositProvider::FAKE_BANK,
        providerRef: 'pix-abc',
        at: new DateTimeImmutable('2026-07-14 12:05:00'),
    )))->toThrow(DomainException::class);

    $fake->assertNothingRecorded();
});

it('cancels the operation when the deposit arrives after the window closes', function () {
    FxOperation::fake('op-123')
        ->given(openQuote())
        ->when(fn (FxOperation $op) => $op->confirmDeposit(
            provider: DepositProvider::FAKE_BANK,
            providerRef: 'pix-abc',
            at: new DateTimeImmutable('2026-07-14 12:20:00'),   // 5 min past 12:15
        ))
        ->assertRecorded([
            new DepositExpired(operationId: 'op-123'),
            new OperationCancelled(
                operationId: 'op-123',
                reason: CancellationReason::DepositWindowElapsed,
            ),
        ]);
});

it('refuses a deposit with a blank providerRef', function () {
    $fake = FxOperation::fake('op-123')->given(openQuote());

    expect(fn () => $fake->when(fn (FxOperation $op) => $op->confirmDeposit(
        provider: DepositProvider::FAKE_BANK,
        providerRef: '   ',
        at: new DateTimeImmutable('2026-07-14 12:05:00'),
    )))->toThrow(DomainException::class);

    $fake->assertNotRecorded(DepositConfirmed::class);
});

it('confirms an already-confirmed deposit at most once (idempotent by providerRef)', function () {
    $history = [...openQuote(), new DepositConfirmed(
        operationId: 'op-123',
        provider: DepositProvider::FAKE_BANK,
        brlAmount: new Money(123456, Currency::BRL),
        providerRef: 'pix-abc',
    )];

    FxOperation::fake('op-123')
        ->given($history)
        ->when(fn (FxOperation $op) => $op->confirmDeposit(
            provider: DepositProvider::FAKE_BANK,
            providerRef: 'pix-abc',   // same ref again
            at: new DateTimeImmutable('2026-07-14 12:06:00'),
        ))
        ->assertNothingRecorded();
});

it('refuses a second deposit with a different providerRef', function () {
    $history = [...openQuote(), new DepositConfirmed(
        operationId: 'op-123',
        provider: DepositProvider::FAKE_BANK,
        brlAmount: new Money(123456, Currency::BRL),
        providerRef: 'pix-abc',
    )];
    $fake = FxOperation::fake('op-123')->given($history);

    expect(fn () => $fake->when(fn (FxOperation $op) => $op->confirmDeposit(
        provider: DepositProvider::FAKE_BANK,
        providerRef: 'pix-other',   // different ref, still inside the window
        at: new DateTimeImmutable('2026-07-14 12:07:00'),
    )))->toThrow(DomainException::class);

    $fake->assertNothingRecorded();
});

// A quote that already expired and cancelled the operation.
function cancelledOperation(): array
{
    return [...openQuote(),
        new DepositExpired(operationId: 'op-123'),
        new OperationCancelled(
            operationId: 'op-123',
            reason: CancellationReason::DepositWindowElapsed,
        ),
    ];
}

it('refuses a deposit once the operation is cancelled', function () {
    $fake = FxOperation::fake('op-123')->given(cancelledOperation());

    expect(fn () => $fake->when(fn (FxOperation $op) => $op->confirmDeposit(
        provider: DepositProvider::FAKE_BANK,
        providerRef: 'pix-late-retry',
        at: new DateTimeImmutable('2026-07-14 12:25:00'),
    )))->toThrow(DomainException::class);

    $fake->assertNothingRecorded();
});

it('refuses a re-quote once the operation is cancelled', function () {
    $fake = FxOperation::fake('op-123')->given(cancelledOperation());

    expect(fn () => $fake->when(fn (FxOperation $op) => $op->createQuote(
        'op-123',
        new Money(100_000, Currency::BRL),
        new Rate(1900),
        spreadBps: 200,
        taxesBps: 100,
        at: new DateTimeImmutable('2026-07-14 12:30:00'),
    )))->toThrow(DomainException::class);

    $fake->assertNothingRecorded();
});
