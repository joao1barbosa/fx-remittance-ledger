<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\FxOperation\ConcludeOperationHandler;
use App\Application\FxOperation\ConfirmDepositHandler;
use App\Application\FxOperation\ConfirmSettlementHandler;
use App\Application\FxOperation\ConvertHandler;
use App\Application\FxOperation\CreateQuoteHandler;
use App\Application\FxOperation\InitiateSettlementHandler;
use App\Application\FxOperation\ScreenComplianceHandler;
use App\Domain\FxOperation\DepositProvider;
use App\Domain\FxOperation\SettlementFill;
use App\Domain\Ledger\LedgerAccount;
use App\Models\LedgerEntry;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use App\Infrastructure\Compliance\FakeComplianceProvider;
use App\Infrastructure\Exchange\FakeCryptoExchange;
use App\Infrastructure\Exchange\FakeExchangeRateProvider;
use App\Infrastructure\Liquidity\FakeLiquidity;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * Drives one remittance end to end through the real call-sites (with the fakes
 * wired in as the webhooks/reactors would wire them in production), then replays
 * the resulting event stream through the double-entry ledger and shows every
 * holding netting to zero — the "no cent lost" guarantee, made runnable.
 */
final class FxDemo extends Command
{
    protected $signature = 'fx:demo';

    protected $description = 'Run one remittance through the whole pipeline and show the ledger closing to zero.';

    public function handle(): int
    {
        $id = (string) Str::uuid();
        $at = new DateTimeImmutable;

        // BRL 1,000.00 at 0.20 USD/BRL less 3% (spread + taxes) quotes to USD 194.00,
        // which the fake exchange fills 1:1 in USDC (zero slippage); the off-ramp then
        // lands USD 193.80 — the 20c gap is the off-ramp fee.
        (new CreateQuoteHandler(new FakeExchangeRateProvider))
            ->handle($id, new Money(100_000, Currency::BRL), spreadBps: 200, taxesBps: 100, at: $at);
        (new ConfirmDepositHandler)
            ->handle($id, DepositProvider::FAKE_BANK, 'pix-'.Str::lower(Str::random(8)), $at);
        (new ScreenComplianceHandler(new FakeComplianceProvider))->handle($id);
        (new ConvertHandler(new FakeCryptoExchange))->handle($id);
        (new InitiateSettlementHandler(new FakeLiquidity))->handle($id);
        // The liquidity provider's off-ramp confirmation — would arrive by webhook.
        (new ConfirmSettlementHandler)
            ->handle($id, new SettlementFill(new Money(19_380, Currency::USD), 'ach-benef-1'));
        (new ConcludeOperationHandler)->handle($id);

        $events = EloquentStoredEvent::query()
            ->where('aggregate_uuid', $id)
            ->orderBy('id')
            ->get()
            ->map(fn (EloquentStoredEvent $e) => $e->toStoredEvent()->event);

        $this->info("Operation {$id}");
        $this->newLine();

        $this->line('<comment>Facts recorded (the event stream):</comment>');
        foreach ($events as $event) {
            $this->line('  - '.class_basename($event));
        }
        $this->newLine();

        // Render from the materialized ledger: a SELECT on the persisted read-model that
        // the LedgerEntryProjector wrote as the operation ran — not a recompute in memory.
        $rows = LedgerEntry::query()
            ->where('aggregate_uuid', $id)
            ->selectRaw('account, sum(debit) as debit, sum(credit) as credit')
            ->groupBy('account')
            ->get()
            ->keyBy('account');

        $this->renderLedger($rows);

        $holdingsBalanced = collect(LedgerAccount::cases())
            ->filter(fn (LedgerAccount $a) => $a->isHolding())
            ->every(fn (LedgerAccount $a) => (int) ($rows[$a->value]->debit ?? 0) - (int) ($rows[$a->value]->credit ?? 0) === 0);

        if ($holdingsBalanced) {
            $this->newLine();
            $this->info('All holdings net to zero — no cent lost. Operation reconciled.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('A holding did not zero — reconciliation discrepancy.');

        return self::FAILURE;
    }

    private function renderLedger(\Illuminate\Support\Collection $ledger): void
    {
        $rows = [];
        foreach (LedgerAccount::cases() as $account) {
            $debit = (int) ($ledger[$account->value]->debit ?? 0);
            $credit = (int) ($ledger[$account->value]->credit ?? 0);

            $rows[] = [
                $account->value,
                $this->currencyOf($account),
                number_format($debit),
                number_format($credit),
                // fx_exchange bridges two currencies, so its net mixes units and is
                // not a balance; every other account is single-currency.
                $account === LedgerAccount::FxExchange ? '-' : number_format($debit - $credit),
                $account->isHolding() ? 'holding' : 'terminal',
            ];
        }

        $this->table(['account', 'ccy', 'debits (c)', 'credits (c)', 'net (c)', 'kind'], $rows);
        $this->line('<comment>holdings must net to 0; terminals carry the outcome; fx_exchange is the BRL<->USDC bridge.</comment>');
    }

    private function currencyOf(LedgerAccount $account): string
    {
        return match ($account) {
            LedgerAccount::Customer, LedgerAccount::BrlHolding => 'BRL',
            LedgerAccount::UsdcHolding => 'USDC',
            LedgerAccount::UsdHolding, LedgerAccount::Fees, LedgerAccount::Beneficiary => 'USD',
            LedgerAccount::FxExchange => 'BRL/USDC',
        };
    }
}
