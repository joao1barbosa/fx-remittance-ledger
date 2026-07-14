<?php

use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;

it('holds an exact integer amount of cents', function () {
    $money = new Money(15000, Currency::BRL);

    expect($money->cents)->toBe(15000)
        ->and($money->currency)->toBe(Currency::BRL);
});

it('adds two amounts of the same currency without float drift', function () {
    $sum = (new Money(10, Currency::BRL))->plus(new Money(20, Currency::BRL));

    expect($sum->cents)->toBe(30);
});

it('compares by value, not identity', function () {
    expect(new Money(100, Currency::BRL))->toEqual(new Money(100, Currency::BRL));
});

it('refuses to add amounts of different currencies', function () {
    (new Money(100, Currency::BRL))->plus(new Money(100, Currency::USD));
})->throws(InvalidArgumentException::class);

it('rejects a negative amount of cents', function () {
    new Money(-1, Currency::BRL);
})->throws(InvalidArgumentException::class);

it('accepts a zero amount of cents', function () {
    expect((new Money(0, Currency::BRL))->cents)->toBe(0);
});
