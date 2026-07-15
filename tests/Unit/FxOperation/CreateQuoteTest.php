<?php

use App\Domain\FxOperation\Events\QuoteCreated;
use App\Domain\FxOperation\FxOperation;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Rate;

it('quotes the exact USD amount for a given rate, spread and taxes', function () {
    FxOperation::fake('op-123')
        ->when(fn (FxOperation $op) => $op->createQuote(
            'op-123',
            new Money(123456, Currency::BRL),
            rate: new Rate(1900),
            spreadBps: 200,
            taxesBps: 100,
            at: new DateTimeImmutable('2026-07-14 12:00:00'),
        ))
        ->assertRecorded(function (QuoteCreated $event) {
            expect($event->quotedUsd->cents)->toBe(22753)
                ->and($event->quotedUsd->currency)->toBe(Currency::USD);
        });
});

it('records quote.created carrying the full quote', function () {
    $at = new DateTimeImmutable('2026-07-14 12:00:00');
    $brl = new Money(123456, Currency::BRL);

    FxOperation::fake('op-123')
        ->when(fn (FxOperation $op) => $op->createQuote(
            'op-123', $brl, rate: new Rate(1900), spreadBps: 200, taxesBps: 100, at: $at,
        ))
        ->assertRecorded(new QuoteCreated(
            operationId: 'op-123',
            brlAmount: $brl,
            rate: new Rate(1900),
            spreadBps: 200,
            taxesBps: 100,
            quotedUsd: new Money(22753, Currency::USD),
            expiresAt: $at->modify('+15 minutes'),
        ));
});

it('sets the expiry 15 minutes after the quote instant', function () {
    $at = new DateTimeImmutable('2026-07-14 12:00:00');

    FxOperation::fake('op-123')
        ->when(fn (FxOperation $op) => $op->createQuote(
            'op-123',
            new Money(123456, Currency::BRL),
            rate: new Rate(1900), spreadBps: 200, taxesBps: 100, at: $at,
        ))
        ->assertRecorded(function (QuoteCreated $event) use ($at) {
            expect($event->expiresAt)->toEqual($at->modify('+15 minutes'));
        });
});
