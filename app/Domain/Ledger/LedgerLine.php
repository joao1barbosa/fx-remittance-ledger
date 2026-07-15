<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

/** One double-entry posting line: an account moved by a debit or a credit, in cents. */
final readonly class LedgerLine
{
    public function __construct(
        public LedgerAccount $account,
        public int $debit,
        public int $credit,
    ) {}
}
