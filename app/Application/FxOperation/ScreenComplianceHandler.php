<?php

declare(strict_types=1);

namespace App\Application\FxOperation;

use App\Domain\FxOperation\ComplianceProvider;
use App\Domain\FxOperation\FxOperation;

/**
 * Call-site for compliance screening: reads the verdict from the provider,
 * then hands it to the pure aggregate as data. The aggregate never touches
 * the provider — dependency inversion keeps I/O at the edge. Synchronous here;
 * production would drive this from a reactor on deposit.confirmed.
 */
final class ScreenComplianceHandler
{
    public function __construct(private ComplianceProvider $compliance) {}

    public function handle(string $operationId): void
    {
        $decision = $this->compliance->screen($operationId);

        FxOperation::retrieve($operationId)
            ->screenCompliance($decision)
            ->persist();
    }
}
