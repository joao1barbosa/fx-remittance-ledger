<?php

use App\Application\FxOperation\ConcludeOperationHandler;
use App\Domain\Shared\Enums\ComplianceDecision;
use App\Domain\Shared\ValueObjects\ConversionFill;
use App\Domain\Shared\Enums\DepositProvider;
use App\Domain\FxOperation\Events\ComplianceApproved;
use App\Domain\FxOperation\Events\DepositConfirmed;
use App\Domain\FxOperation\Events\FundsConverted;
use App\Domain\FxOperation\Events\OperationReconciled;
use App\Domain\FxOperation\Events\PayoutCompleted;
use App\Domain\FxOperation\Events\QuoteCreated;
use App\Domain\FxOperation\Events\ReconciliationDiscrepancy;
use App\Domain\FxOperation\Events\SettlementCompleted;
use App\Domain\FxOperation\Events\SettlementInitiated;
use App\Domain\FxOperation\FxOperation;
use App\Domain\Shared\ValueObjects\SettlementFill;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Rate;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Spatie\EventSourcing\StoredEvents\Repositories\StoredEventRepository;

function recordedEvent(string $uuid, string $suffix): bool
{
    return EloquentStoredEvent::query()
        ->where('aggregate_uuid', $uuid)
        ->where('event_class', 'like', '%'.$suffix)
        ->exists();
}

it('reconciles a fully settled operation whose holdings zero out', function () {
    $uuid = (string) Str::uuid();
    $at = new DateTimeImmutable('2026-07-14 12:00:00');

    // The whole happy path, driven through the real commands.
    FxOperation::retrieve($uuid)
        ->createQuote($uuid, new Money(123456, Currency::BRL), new Rate(1900), 200, 100, $at)
        ->confirmDeposit(DepositProvider::FAKE_BANK, 'pix-abc', $at->modify('+5 minutes'))
        ->screenCompliance(ComplianceDecision::Approved)
        ->convert(new ConversionFill(new Money(22753, Currency::USDC), new Rate(1900), 'ord-1'))
        ->initiateSettlement('sett-1')
        ->confirmSettlement(new SettlementFill(new Money(22730, Currency::USD), 'ach-9'))
        ->persist();

    app(ConcludeOperationHandler::class)->handle($uuid);

    expect(recordedEvent($uuid, 'OperationReconciled'))->toBeTrue()
        ->and(recordedEvent($uuid, 'ReconciliationDiscrepancy'))->toBeFalse();
});

it('flags a discrepancy when a cent is stuck in a holding', function () {
    $uuid = (string) Str::uuid();
    $at = new DateTimeImmutable('2026-07-14 12:00:00');

    // A corrupted stream: the payout under-delivers by one cent, so usd_holding
    // does not zero — exactly what reconcile exists to catch.
    $events = [
        new QuoteCreated(
            operationId: $uuid,
            brlAmount: new Money(123456, Currency::BRL),
            rate: new Rate(1900),
            spreadBps: 200,
            taxesBps: 100,
            quotedUsd: new Money(22753, Currency::USD),
            expiresAt: $at->modify('+15 minutes'),
        ),
        new DepositConfirmed($uuid, DepositProvider::FAKE_BANK, new Money(123456, Currency::BRL), 'pix-abc'),
        new ComplianceApproved($uuid),
        new FundsConverted(
            operationId: $uuid,
            brlAmount: new Money(123456, Currency::BRL),
            quotedUsd: new Money(22753, Currency::USD),
            executedUsdc: new Money(22753, Currency::USDC),
            executedRate: new Rate(1900),
            orderRef: 'ord-1',
        ),
        new SettlementInitiated($uuid, new Money(22753, Currency::USDC), 'sett-1'),
        new SettlementCompleted($uuid, new Money(22730, Currency::USD)),
        new PayoutCompleted($uuid, new Money(22729, Currency::USD), 'ach-9'), // one cent short
    ];
    foreach ($events as $version => $event) {
        $event->setAggregateRootVersion($version + 1);
    }
    app(StoredEventRepository::class)->persistMany($events, $uuid);

    app(ConcludeOperationHandler::class)->handle($uuid);

    expect(recordedEvent($uuid, 'ReconciliationDiscrepancy'))->toBeTrue()
        ->and(recordedEvent($uuid, 'OperationReconciled'))->toBeFalse();
});
