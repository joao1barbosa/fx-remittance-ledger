<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\FxOperation\Events\DepositConfirmed;
use App\Domain\FxOperation\Events\FundsConverted;
use App\Domain\FxOperation\Events\PayoutCompleted;
use App\Domain\FxOperation\Events\SettlementCompleted;

/**
 * The double-entry ledger as a projection over the operation's events. Rebuilt by
 * replay — never a manual balance UPDATE. Each posting is balanced within a single
 * currency; the reconciliation invariant is that every holding nets to zero.
 */
final class LedgerProjector
{
    /** @var list<LedgerLine> */
    private array $lines = [];

    /** The USDC obtained at conversion, carried so settlement can book its off-ramp fee. */
    private int $convertedUsdc = 0;

    public function project(iterable $events): self
    {
        foreach ($events as $event) {
            match (true) {
                $event instanceof DepositConfirmed => $this->onDeposit($event),
                $event instanceof FundsConverted => $this->onConversion($event),
                $event instanceof SettlementCompleted => $this->onSettlement($event),
                $event instanceof PayoutCompleted => $this->onPayout($event),
                default => null, // events with no ledger effect (quote, compliance, ...)
            };
        }

        return $this;
    }

    /** @return list<LedgerLine> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** Net balance of an account: debits minus credits, in cents. */
    public function balance(LedgerAccount $account): int
    {
        $net = 0;
        foreach ($this->lines as $line) {
            if ($line->account === $account) {
                $net += $line->debit - $line->credit;
            }
        }

        return $net;
    }

    /** The reconciliation verdict: no cent stuck in any intermediate holding. */
    public function holdingsBalanced(): bool
    {
        foreach (LedgerAccount::cases() as $account) {
            if ($account->isHolding() && $this->balance($account) !== 0) {
                return false;
            }
        }

        return true;
    }

    // The customer's BRL lands in the holding.
    private function onDeposit(DepositConfirmed $event): void
    {
        $brl = $event->brlAmount->cents;
        $this->post(LedgerAccount::BrlHolding, debit: $brl);
        $this->post(LedgerAccount::Customer, credit: $brl);
    }

    // BRL leaves to the exchange; USDC comes back from it. Two single-currency legs.
    private function onConversion(FundsConverted $event): void
    {
        $brl = $event->brlAmount->cents;
        $this->convertedUsdc = $event->executedUsdc->cents;

        $this->post(LedgerAccount::FxExchange, debit: $brl);
        $this->post(LedgerAccount::BrlHolding, credit: $brl);

        $this->post(LedgerAccount::UsdcHolding, debit: $this->convertedUsdc);
        $this->post(LedgerAccount::FxExchange, credit: $this->convertedUsdc);
    }

    // USDC off-ramps to USD; the gap (USDC 1:1 USD) is the off-ramp fee.
    private function onSettlement(SettlementCompleted $event): void
    {
        $usd = $event->usdAmount->cents;
        $fee = $this->convertedUsdc - $usd;

        $this->post(LedgerAccount::UsdHolding, debit: $usd);
        $this->post(LedgerAccount::Fees, debit: $fee);
        $this->post(LedgerAccount::UsdcHolding, credit: $this->convertedUsdc);
    }

    // The settled USD is delivered to the beneficiary.
    private function onPayout(PayoutCompleted $event): void
    {
        $usd = $event->usdAmount->cents;
        $this->post(LedgerAccount::Beneficiary, debit: $usd);
        $this->post(LedgerAccount::UsdHolding, credit: $usd);
    }

    private function post(LedgerAccount $account, int $debit = 0, int $credit = 0): void
    {
        $this->lines[] = new LedgerLine($account, $debit, $credit);
    }
}
