<?php

declare(strict_types=1);

namespace App\Domain\FxOperation\Ports;

use App\Domain\Shared\Enums\ComplianceDecision;

/**
 * Port for compliance screening (KYC/KYB, sanctions, PEP, adverse media).
 * A real adapter takes the customer identity; the operationId is the handle
 * for now. Returns the decision only — the aggregate records the fact.
 */
interface ComplianceProvider
{
    public function screen(string $operationId): ComplianceDecision;
}
