<?php

declare(strict_types=1);

namespace App\Domain\FxOperation;

// The screening provider's verdict, passed into the aggregate as data. Cleared
// lets the operation proceed to conversion; the Flagged path is a later slice.
enum ComplianceDecision: string
{
    case Cleared = 'cleared';
}
