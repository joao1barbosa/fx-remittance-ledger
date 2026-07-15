<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

// Which integration reported a deposit — carried on deposit events so the audit
// trail names the source without a lookup. Serializes as its scalar value via
// the BackedEnumNormalizer already registered for the store.
// ponytail: one fake rail for now; add real PSPs (and a QuoteProvider enum) here as they land.
enum DepositProvider: string
{
    case FAKE_BANK = 'fake_bank';
}
