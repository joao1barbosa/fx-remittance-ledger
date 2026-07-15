<?php

declare(strict_types=1);

namespace App\Application\FxOperation;

use App\Domain\FxOperation\FxOperation;
use App\Domain\FxOperation\Ports\LiquidityProvider;

/**
 * Call-site for the off-ramp: reads the executed USDC off the aggregate to size
 * the order, opens it on the provider, then records the pending fact — I/O at the edge.
 */
final class InitiateSettlementHandler
{
    public function __construct(private LiquidityProvider $liquidity) {}

    public function handle(string $operationId): void
    {
        $operation = FxOperation::retrieve($operationId);

        $orderRef = $this->liquidity->offRamp($operation->usdcAmount());

        $operation->initiateSettlement($orderRef)->persist();
    }
}
