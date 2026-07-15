<?php

use App\Domain\FxOperation\FxOperation;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Rate;

// A valid createQuote call; each test mutates exactly one input to break one
// invariant. All money math is integer; the aggregate stays pure.
function quoteWith(Money $brlAmount, int $spreadBps = 200, int $taxesBps = 100): void
{
    FxOperation::fake('op-guard')->when(fn (FxOperation $op) => $op->createQuote(
        'op-guard',
        $brlAmount,
        new Rate(1900),
        spreadBps: $spreadBps,
        taxesBps: $taxesBps,
        at: new DateTimeImmutable('2026-07-14 12:00:00'),
    ));
}

it('refuses to quote a non-BRL amount', function () {
    quoteWith(new Money(100_000, Currency::USD));
})->throws(DomainException::class);

it('refuses to quote a zero amount', function () {
    quoteWith(new Money(0, Currency::BRL));
})->throws(DomainException::class);

it('refuses a negative spread', function () {
    quoteWith(new Money(100_000, Currency::BRL), spreadBps: -1);
})->throws(DomainException::class);

it('refuses negative taxes', function () {
    quoteWith(new Money(100_000, Currency::BRL), taxesBps: -1);
})->throws(DomainException::class);

it('refuses spread plus taxes at or above 100%', function () {
    quoteWith(new Money(100_000, Currency::BRL), spreadBps: 6_000, taxesBps: 4_000);
})->throws(DomainException::class);

it('refuses a quote whose value rounds to zero USD', function () {
    // 1 cent BRL at 0.20 USD/BRL less 3% rounds to 0 USD — a meaningless quote that
    // must be refused at the source, before it can crash convert's slippage division.
    $fake = FxOperation::fake('op-1');

    expect(fn () => $fake->when(fn (FxOperation $op) => $op->createQuote(
        'op-1', new Money(1, Currency::BRL), new Rate(2000), 200, 100,
        new DateTimeImmutable('2026-07-14 12:00:00'),
    )))->toThrow(DomainException::class);

    $fake->assertNothingRecorded();
});

it('records nothing when an invariant is violated — failure is not a fact', function () {
    $fake = FxOperation::fake('op-guard');

    expect(fn () => $fake->when(fn (FxOperation $op) => $op->createQuote(
        'op-guard',
        new Money(100_000, Currency::USD),   // non-BRL: violates the currency invariant
        new Rate(1900),
        spreadBps: 200,
        taxesBps: 100,
        at: new DateTimeImmutable('2026-07-14 12:00:00'),
    )))->toThrow(DomainException::class);

    $fake->assertNothingRecorded();
});
