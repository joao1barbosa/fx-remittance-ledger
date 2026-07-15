<?php

declare(strict_types=1);

namespace App\Application\FxOperation;

use App\Domain\FxOperation\CryptoExchange;
use App\Domain\FxOperation\FxOperation;

/**
 * Call-site for the conversion: executes the order on the exchange, then hands
 * the resulting fill to the pure aggregate as data — I/O stays at the edge.
 */
final class ConvertHandler
{
    public function __construct(private CryptoExchange $exchange) {}

    public function handle(string $operationId): void
    {
        $operation = FxOperation::retrieve($operationId);

        $fill = $this->exchange->execute($operation->brlAmount());

        $operation->convert($fill)->persist();
    }
}
