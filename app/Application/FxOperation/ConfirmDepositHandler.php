<?php

declare(strict_types=1);

namespace App\Application\FxOperation;

use App\Domain\FxOperation\DepositProvider;
use App\Domain\FxOperation\FxOperation;
use DateTimeImmutable;

/**
 * Canonical entry point for a confirmed deposit: provider-agnostic inputs in,
 * one command on the pure aggregate. Unlike the quote/compliance seams there is
 * no provider dependency — the payload already arrived, inbound has nothing to
 * call outward. Payload-shape and auth live in the per-provider webhook edge.
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
