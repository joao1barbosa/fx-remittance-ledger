<?php

use App\Application\FxOperation\ConvertHandler;
use App\Domain\Shared\Enums\ComplianceDecision;
use App\Domain\Shared\ValueObjects\ConversionFill;
use App\Domain\Shared\Enums\DepositProvider;
use App\Domain\FxOperation\Events\FundsConverted;
use App\Domain\FxOperation\FxOperation;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Rate;
use App\Infrastructure\Exchange\FakeCryptoExchange;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

it('converts an approved operation through the crypto exchange', function () {
    $uuid = (string) Str::uuid();
    $at = new DateTimeImmutable('2026-07-14 12:00:00');

    FxOperation::retrieve($uuid)
        ->createQuote($uuid, new Money(100_000, Currency::BRL), new Rate(2000), 200, 100, $at)
        ->confirmDeposit(DepositProvider::FAKE_BANK, 'pix-abc', $at->modify('+5 minutes'))
        ->screenCompliance(ComplianceDecision::Approved)
        ->persist();

    $fill = new ConversionFill(
        usdc: new Money(19400, Currency::USDC),
        executedRate: new Rate(2000),
        orderRef: 'ord-1',
    );

    (new ConvertHandler(new FakeCryptoExchange($fill)))->handle($uuid);

    expect(EloquentStoredEvent::query()->where('event_class', 'like', '%FundsConverted')->exists())->toBeTrue();
});
