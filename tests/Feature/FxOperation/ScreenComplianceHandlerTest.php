<?php

use App\Application\FxOperation\ScreenComplianceHandler;
use App\Domain\FxOperation\DepositProvider;
use App\Domain\FxOperation\Events\ComplianceApproved;
use App\Domain\FxOperation\FxOperation;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use App\Domain\Shared\Rate;
use App\Infrastructure\Compliance\FakeComplianceProvider;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

it('reads the verdict from the provider and records compliance.approved', function () {
    $uuid = (string) Str::uuid();
    $at = new DateTimeImmutable('2026-07-14 12:00:00');

    FxOperation::retrieve($uuid)
        ->createQuote($uuid, new Money(100_000, Currency::BRL), new Rate(2000), 200, 100, $at)
        ->confirmDeposit(DepositProvider::FAKE_BANK, 'pix-abc', $at->modify('+5 minutes'))
        ->persist();

    (new ScreenComplianceHandler(new FakeComplianceProvider()))->handle($uuid);

    $event = EloquentStoredEvent::query()->get()->last()->toStoredEvent()->event;
    expect($event)->toBeInstanceOf(ComplianceApproved::class);
});
