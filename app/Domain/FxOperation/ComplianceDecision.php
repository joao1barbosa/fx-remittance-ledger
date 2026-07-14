<?php

declare(strict_types=1);

namespace App\Domain\FxOperation;

// The screening provider's verdict, passed into the aggregate as data. Approved
// lets the operation proceed to conversion; ReviewRequired pauses it for a human.
enum ComplianceDecision: string
{
    case Approved = 'approved';
    case ReviewRequired = 'review_required';
}
