<?php

declare(strict_types=1);

namespace App\Infrastructure\Liquidity;

use App\Domain\FxOperation\LiquidityProvider;
use App\Domain\Shared\Money;

/** Returns a preset order ref for tests and the slice; defaults deterministic. No I/O. */
final class FakeLiquidity implements LiquidityProvider
{
    public function __construct(private string $orderRef = 'fake-sett-1') {}

    public function offRamp(Money $usdc): string
    {
        return $this->orderRef;
    }
}
