<?php

declare(strict_types=1);

namespace App\Domain\Shared;

enum Currency: string
{
    case BRL = 'BRL';
    case USD = 'USD';
}
