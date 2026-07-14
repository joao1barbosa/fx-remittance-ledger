<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;

final readonly class Money
{
    public function __construct(
        public int $cents,
        public Currency $currency,
    ) {}

    public function plus(self $other): self
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException(
                "Cannot add {$other->currency->value} to {$this->currency->value}.",
            );
        }

        return new self($this->cents + $other->cents, $this->currency);
    }
}
