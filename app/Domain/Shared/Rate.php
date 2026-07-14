<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;

/**
 * A mid-market FX rate as a scaled integer (4 decimals): 0.1900 -> 1900.
 * Mirrors Money — money-critical values never live as floats.
 */
final readonly class Rate
{
    public const SCALE = 10_000;

    public function __construct(public int $scaled)
    {
        if ($scaled <= 0) {
            throw new InvalidArgumentException("Rate must be positive; got {$scaled}.");
        }
    }
}
