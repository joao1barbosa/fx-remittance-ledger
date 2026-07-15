<?php

use App\Domain\Shared\Enums\Currency;

it('rejects unknown currency codes at the parse boundary', function () {
    Currency::from('XYZ');
})->throws(ValueError::class);
