<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance;

use App\Domain\FxOperation\ComplianceDecision;
use App\Domain\FxOperation\ComplianceProvider;

/**
 * Deterministic screening for tests and the vertical slice: always approves.
 * No I/O. A review-path handler test would inject a stubbed verdict instead.
 */
final class FakeComplianceProvider implements ComplianceProvider
{
    public function screen(string $operationId): ComplianceDecision
    {
        return ComplianceDecision::Approved;
    }
}
