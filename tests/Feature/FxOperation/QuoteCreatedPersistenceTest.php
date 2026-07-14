<?php

use App\Domain\FxOperation\Events\QuoteCreated;
use App\Domain\FxOperation\FxOperation;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use App\Domain\Shared\Rate;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

it('round-trips QuoteCreated value objects through the event store', function () {
    $uuid = (string) Str::uuid();
    $at = new DateTimeImmutable('2026-07-14 12:00:00');

    FxOperation::retrieve($uuid)
        ->createQuote($uuid, new Money(123456, Currency::BRL), new Rate(1900), 200, 100, $at)
        ->persist();

    // Read the event back as spatie deserialized it from stored_events (jsonb),
    // not as the in-memory object we recorded.
    $event = EloquentStoredEvent::query()->firstOrFail()->toStoredEvent()->event;

    expect($event)->toBeInstanceOf(QuoteCreated::class)
        ->and($event->brlAmount)->toEqual(new Money(123456, Currency::BRL))
        ->and($event->rate)->toEqual(new Rate(1900))
        ->and($event->spreadBps)->toBe(200)
        ->and($event->taxesBps)->toBe(100)
        ->and($event->quotedUsd)->toEqual(new Money(22753, Currency::USD))
        ->and($event->expiresAt)->toEqual($at->modify('+15 minutes'));
});
