<?php

use App\Domain\Shared\Enums\ComplianceDecision;
use App\Domain\Shared\Enums\DepositProvider;
use App\Domain\FxOperation\Events\ComplianceApproved;
use App\Domain\FxOperation\Events\ComplianceReviewRequired;
use App\Domain\FxOperation\Events\DepositConfirmed;
use App\Domain\FxOperation\Events\OperationCancelled;
use App\Domain\FxOperation\Events\QuoteCreated;
use App\Domain\FxOperation\FxOperation;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Rate;

// A quote whose deposit is already confirmed — the state compliance screens against.
function confirmedDeposit(): array
{
    $at = new DateTimeImmutable('2026-07-14 12:00:00');

    return [
        new QuoteCreated(
            operationId: 'op-123',
            brlAmount: new Money(123456, Currency::BRL),
            rate: new Rate(1900),
            spreadBps: 200,
            taxesBps: 100,
            quotedUsd: new Money(22753, Currency::USD),
            expiresAt: $at->modify('+15 minutes'),
        ),
        new DepositConfirmed(
            operationId: 'op-123',
            provider: DepositProvider::FAKE_BANK,
            brlAmount: new Money(123456, Currency::BRL),
            providerRef: 'pix-abc',
        ),
    ];
}

it('approves compliance on a confirmed deposit', function () {
    FxOperation::fake('op-123')
        ->given(confirmedDeposit())
        ->when(fn (FxOperation $op) => $op->screenCompliance(
            decision: ComplianceDecision::Approved,
        ))
        ->assertRecorded(new ComplianceApproved(operationId: 'op-123'));
});

it('refuses compliance screening before a deposit is confirmed', function () {
    $fake = FxOperation::fake('op-123')->given(openQuote());

    expect(fn () => $fake->when(fn (FxOperation $op) => $op->screenCompliance(
        decision: ComplianceDecision::Approved,
    )))->toThrow(DomainException::class);

    $fake->assertNothingRecorded();
});

it('refuses compliance screening once the operation is cancelled', function () {
    $fake = FxOperation::fake('op-123')->given(cancelledOperation());

    expect(fn () => $fake->when(fn (FxOperation $op) => $op->screenCompliance(
        decision: ComplianceDecision::Approved,
    )))->toThrow(DomainException::class);

    $fake->assertNothingRecorded();
});

it('screens compliance at most once', function () {
    $history = [...confirmedDeposit(), new ComplianceApproved(operationId: 'op-123')];
    $fake = FxOperation::fake('op-123')->given($history);

    expect(fn () => $fake->when(fn (FxOperation $op) => $op->screenCompliance(
        decision: ComplianceDecision::Approved,
    )))->toThrow(DomainException::class);

    $fake->assertNothingRecorded();
});

it('opens manual review when screening flags a match', function () {
    FxOperation::fake('op-123')
        ->given(confirmedDeposit())
        ->when(fn (FxOperation $op) => $op->screenCompliance(
            decision: ComplianceDecision::ReviewRequired,
        ))
        ->assertRecorded(new ComplianceReviewRequired(operationId: 'op-123'))
        ->assertNotRecorded(OperationCancelled::class);
});
