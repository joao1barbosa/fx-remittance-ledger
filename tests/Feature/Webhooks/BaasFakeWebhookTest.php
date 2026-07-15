<?php

use App\Domain\FxOperation\Events\DepositConfirmed;
use App\Domain\FxOperation\FxOperation;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Rate;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

it('confirms the deposit from a webhook', function () {
    config(['services.baas_fake.webhook_secret' => 'test-secret']);
    $uuid = (string) Str::uuid();
    $at = new DateTimeImmutable('2026-07-14 12:00:00');

    FxOperation::retrieve($uuid)
        ->createQuote($uuid, new Money(100_000, Currency::BRL), new Rate(2000), 200, 100, $at)
        ->persist();

    $this->withHeader('X-Webhook-Secret', 'test-secret')
        ->postJson('/webhooks/baas-fake', [
            'reference'     => $uuid,                       // BaaS field -> operationId
            'end_to_end_id' => 'E-abc-123',                 // BaaS field -> providerRef
            'paid_at'       => '2026-07-14T12:05:00+00:00', // BaaS field -> at
        ])
        ->assertAccepted();

    $event = EloquentStoredEvent::query()->where('aggregate_uuid', $uuid)->orderBy('id')->get()->last()->toStoredEvent()->event;
    expect($event)->toBeInstanceOf(DepositConfirmed::class)
        ->and($event->providerRef)->toBe('E-abc-123');
});

it('rejects a webhook with a bad secret', function () {
    config(['services.baas_fake.webhook_secret' => 'test-secret']);
    $uuid = (string) Str::uuid();
    $at = new DateTimeImmutable('2026-07-14 12:00:00');

    FxOperation::retrieve($uuid)
        ->createQuote($uuid, new Money(100_000, Currency::BRL), new Rate(2000), 200, 100, $at)
        ->persist();

    $this->withHeader('X-Webhook-Secret', 'wrong')
        ->postJson('/webhooks/baas-fake', [
            'reference'     => $uuid,
            'end_to_end_id' => 'x',
            'paid_at'       => '2026-07-14T12:05:00+00:00',
        ])
        ->assertUnauthorized();

    expect(EloquentStoredEvent::query()->where('event_class', 'like', '%DepositConfirmed')->exists())->toBeFalse();
});
