<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum Currency: string
{
    case BRL = 'BRL';
    case USD = 'USD';
    // dollar-backed stablecoin — the USDC conversion leg
    case USDC = 'USDC';
}
