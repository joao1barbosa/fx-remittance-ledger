<?php

declare(strict_types=1);

namespace App\Domain\FxOperation;

// The screening provider's verdict, passed into the aggregate as data. Approved
// lets the operation proceed to conversion; the review path is a later slice.
enum ComplianceDecision: string
{
    case Approved = 'approved';
}
