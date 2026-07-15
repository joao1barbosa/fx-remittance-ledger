<?php

declare(strict_types=1);

namespace App\Application\FxOperation;

use App\Domain\FxOperation\FxOperation;
use App\Domain\FxOperation\SettlementFill;

/**
 * Canonical entry point for a completed off-ramp: provider-agnostic fill in,
 * one command on the pure aggregate (which records settlement + payout).
 */
final class ConfirmSettlementHandler
{
    public function handle(string $operationId, SettlementFill $fill): void
    {
        FxOperation::retrieve($operationId)
            ->confirmSettlement($fill)
            ->persist();
    }
}
