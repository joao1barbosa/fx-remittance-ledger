<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance;

use App\Domain\Shared\Enums\ComplianceDecision;
use App\Domain\FxOperation\Ports\ComplianceProvider;

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
