<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Application\FxOperation\ConfirmDepositHandler;
use App\Domain\Shared\Enums\DepositProvider;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-provider inbound edge for the fake BaaS: the anti-corruption layer that
 * turns this provider's payload shape into the canonical inputs, then delegates
 * to the provider-agnostic ConfirmDepositHandler.
 */
final class BaasFakeWebhookController
{
    public function __invoke(Request $request, ConfirmDepositHandler $handler): JsonResponse
    {
        // TRUST BOUNDARY: reject before any work if the shared secret mismatches.
        $secret = (string) config('services.baas_fake.webhook_secret');
        abort_if($secret === '', 500, 'BaaS webhook secret is not configured.');
        abort_unless(
            hash_equals(
                $secret,
                (string) $request->header('X-Webhook-Secret')
            ),
            401
        );

        $payload = $request->validate([
            'reference' => ['required', 'string'],
            'end_to_end_id' => ['required', 'string'],
            'paid_at' => ['required', 'date'],
        ]);

        // NORMALIZE: BaaS field names -> canonical, provider-agnostic inputs.
        $handler->handle(
            operationId: $payload['reference'],
            provider: DepositProvider::FAKE_BANK,
            providerRef: $payload['end_to_end_id'],
            at: new DateTimeImmutable($payload['paid_at']),
        );

        return response()->json(status: 202);
    }
}
