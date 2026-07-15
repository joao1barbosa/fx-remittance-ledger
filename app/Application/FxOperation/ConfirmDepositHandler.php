<?php

declare(strict_types=1);

namespace App\Application\FxOperation;

use App\Domain\Shared\Enums\DepositProvider;
use App\Domain\FxOperation\FxOperation;
use DateTimeImmutable;

/**
 * Canonical entry point for a confirmed deposit: provider-agnostic inputs in,
 * one command on the pure aggregate.
 */
final class ConfirmDepositHandler
{
    public function handle(
        string $operationId,
        DepositProvider $provider,
        string $providerRef,
        DateTimeImmutable $at,
    ): void {
        FxOperation::retrieve($operationId)
            ->confirmDeposit($provider, $providerRef, $at)
            ->persist();
    }
}
