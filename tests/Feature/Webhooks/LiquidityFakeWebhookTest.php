<?php

use App\Domain\Shared\Enums\ComplianceDecision;
use App\Domain\Shared\ValueObjects\ConversionFill;
use App\Domain\Shared\Enums\DepositProvider;
use App\Domain\FxOperation\Events\PayoutCompleted;
use App\Domain\FxOperation\Events\SettlementCompleted;
use App\Domain\FxOperation\FxOperation;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Rate;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

// Drive the pipeline to an initiated off-ramp, ready for the completing webhook.
function initiatedOperationInStore(string $uuid): void
{
    $at = new DateTimeImmutable('2026-07-14 12:00:00');

    FxOperation::retrieve($uuid)
        ->createQuote($uuid, new Money(123456, Currency::BRL), new Rate(1900), 200, 100, $at)
        ->confirmDeposit(DepositProvider::FAKE_BANK, 'pix-abc', $at->modify('+5 minutes'))
        ->screenCompliance(ComplianceDecision::Approved)
        ->convert(new ConversionFill(new Money(22753, Currency::USDC), new Rate(1900), 'ord-1'))
        ->initiateSettlement('sett-1')
        ->persist();
}

it('completes settlement and payout from a webhook', function () {
    config(['services.liquidity_fake.webhook_secret' => 'test-secret']);
    $uuid = (string) Str::uuid();
    initiatedOperationInStore($uuid);

    $this->withHeader('X-Webhook-Secret', 'test-secret')
        ->postJson('/webhooks/liquidity-fake', [
            'reference'         => $uuid,      // provider field -> operationId
            'settled_usd_cents' => 22730,      // provider field -> USD (off-ramp fee already taken)
            'destination_ref'   => 'ach-9',    // provider field -> destinationRef
        ])
        ->assertAccepted();

    $classes = EloquentStoredEvent::query()->pluck('event_class');
    expect($classes)->toContain(SettlementCompleted::class)
        ->and($classes)->toContain(PayoutCompleted::class);
});

it('rejects a webhook with a bad secret', function () {
    config(['services.liquidity_fake.webhook_secret' => 'test-secret']);
    $uuid = (string) Str::uuid();
    initiatedOperationInStore($uuid);

    $this->withHeader('X-Webhook-Secret', 'wrong')
        ->postJson('/webhooks/liquidity-fake', [
            'reference'         => $uuid,
            'settled_usd_cents' => 22730,
            'destination_ref'   => 'ach-9',
        ])
        ->assertUnauthorized();

    expect(EloquentStoredEvent::query()->where('event_class', 'like', '%PayoutCompleted')->exists())->toBeFalse();
});
