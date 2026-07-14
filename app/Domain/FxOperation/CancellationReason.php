<?php

declare(strict_types=1);

namespace App\Domain\FxOperation;

// Why an operation was cancelled, carried on operation.cancelled so the audit
// trail names the cause without a lookup.
enum CancellationReason: string
{
    case DepositWindowElapsed = 'deposit_window_elapsed';
}
