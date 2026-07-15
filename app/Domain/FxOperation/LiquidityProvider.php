<?php

declare(strict_types=1);

namespace App\Domain\FxOperation;

use App\Domain\Shared\Money;

/**
 * Open a USDC->USD off-ramp order and return its ref. Async — the
 * completing fill arrives later via the provider's webhook, not this call.
 */
interface LiquidityProvider
{
    public function offRamp(Money $usdc): string;
}
