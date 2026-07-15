<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * The chart of accounts for one operation. Each account is single-currency, so
 * balances never mix currencies. Holdings are intermediate: they take value on
 * an inbound leg and pay it out on an outbound leg, and MUST net to zero once the
 * operation closes — leftover in a holding is the reconciliation discrepancy.
 * Terminals (customer, beneficiary, fees, fx_exchange) legitimately carry balance.
 */
enum LedgerAccount: string
{
    case Customer = 'customer';
    case BrlHolding = 'brl_holding';
    case UsdcHolding = 'usdc_holding';
    case UsdHolding = 'usd_holding';
    case Fees = 'fees';
    case Beneficiary = 'beneficiary';
    // External liquidity counterparty — closes the cross-currency conversion legs
    // into balanced single-currency postings (BRL out here, USDC in there).
    case FxExchange = 'fx_exchange';

    public function isHolding(): bool
    {
        return match ($this) {
            self::BrlHolding, self::UsdcHolding, self::UsdHolding => true,
            default => false,
        };
    }
}
