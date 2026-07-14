<?php

use App\Domain\FxOperation\Events\QuoteCreated;
use App\Domain\FxOperation\FxOperation;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use App\Domain\Shared\Rate;

// Isolates the divide: spread = taxes = 0 (netBps = 10_000), so quotedUsd is
// exactly brlCents * rateScaled / SCALE, rounded half-up at the cent boundary.
it('rounds the quoted USD half-up at the cent boundary', function (int $brlCents, int $rateScaled, int $expectedUsdCents) {
    FxOperation::fake('op-round')
        ->when(fn (FxOperation $op) => $op->createQuote(
            'op-round',
            new Money($brlCents, Currency::BRL),
            new Rate($rateScaled),
            spreadBps: 0,
            taxesBps: 0,
            at: new DateTimeImmutable('2026-07-14 12:00:00'),
        ))
        ->assertRecorded(function (QuoteCreated $event) use ($expectedUsdCents) {
            expect($event->quotedUsd->cents)->toBe($expectedUsdCents);
        });
})->with([
    'rounds down: 50.49 -> 50'        => [100, 5049, 50],
    'exact half rounds up: 50.50 -> 51' => [101, 5000, 51],
    'rounds up: 50.51 -> 51'          => [100, 5051, 51],
]);
