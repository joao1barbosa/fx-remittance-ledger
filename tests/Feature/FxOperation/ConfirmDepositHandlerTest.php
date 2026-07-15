<?php

use App\Application\FxOperation\ConfirmDepositHandler;
use App\Domain\Shared\Enums\DepositProvider;
use App\Domain\FxOperation\Events\DepositConfirmed;
use App\Domain\FxOperation\FxOperation;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Rate;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

it('confirms a deposit from normalized inputs', function () {
    $uuid = (string) Str::uuid();
    $at = new DateTimeImmutable('2026-07-14 12:00:00');

    FxOperation::retrieve($uuid)
        ->createQuote($uuid, new Money(100_000, Currency::BRL), new Rate(2000), 200, 100, $at)
        ->persist();

    (new ConfirmDepositHandler())->handle(
        operationId: $uuid,
        provider: DepositProvider::FAKE_BANK,
        providerRef: 'E-abc-123',
        at: $at->modify('+5 minutes'),
    );

    $event = EloquentStoredEvent::query()->get()->last()->toStoredEvent()->event;
    expect($event)->toBeInstanceOf(DepositConfirmed::class)
        ->and($event->providerRef)->toBe('E-abc-123');
});
