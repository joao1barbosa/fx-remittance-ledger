<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Application\FxOperation\ConfirmSettlementHandler;
use App\Domain\FxOperation\SettlementFill;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-provider inbound edge for the fake liquidity provider: the anti-corruption
 * layer that turns this provider's off-ramp confirmation into the canonical
 * SettlementFill, then delegates to the provider-agnostic ConfirmSettlementHandler.
 */
final class LiquidityFakeWebhookController
{
    public function __invoke(Request $request, ConfirmSettlementHandler $handler): JsonResponse
    {
        // TRUST BOUNDARY: reject before any work if the shared secret mismatches.
        $secret = (string) config('services.liquidity_fake.webhook_secret');
        abort_if($secret === '', 500, 'Liquidity webhook secret is not configured.');
        abort_unless(
            hash_equals(
                $secret,
                (string) $request->header('X-Webhook-Secret')
            ),
            401
        );

        $payload = $request->validate([
            'reference' => ['required', 'string'],
            'settled_usd_cents' => ['required', 'integer', 'min:0'],
            'destination_ref' => ['required', 'string'],
        ]);

        // NORMALIZE: provider field names -> canonical, provider-agnostic fill.
        $handler->handle(
            operationId: $payload['reference'],
            fill: new SettlementFill(
                usd: new Money($payload['settled_usd_cents'], Currency::USD),
                destinationRef: $payload['destination_ref'],
            ),
        );

        return response()->json(status: 202);
    }
}
