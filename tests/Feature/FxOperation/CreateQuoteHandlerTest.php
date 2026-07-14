<?php

use App\Application\FxOperation\CreateQuoteHandler;
use App\Domain\FxOperation\Events\QuoteCreated;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use App\Domain\Shared\Rate;
use App\Infrastructure\Exchange\FakeExchangeRateProvider;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

it('reads the rate from the provider and records quote.created', function () {
    $uuid = (string) Str::uuid();
    $at = new DateTimeImmutable('2026-07-14 12:00:00');

    (new CreateQuoteHandler(new FakeExchangeRateProvider()))
        ->handle($uuid, new Money(100_000, Currency::BRL), spreadBps: 200, taxesBps: 100, at: $at);

    $event = EloquentStoredEvent::query()->firstOrFail()->toStoredEvent()->event;

    expect($event)->toBeInstanceOf(QuoteCreated::class)
        ->and($event->rate)->toEqual(new Rate(2000))            // the provider's rate flowed in
        ->and($event->quotedUsd)->toEqual(new Money(19_400, Currency::USD));
});
