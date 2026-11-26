<?php

namespace Arkhan\Ledger\Console;

use Arkhan\Ledger\Ledger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class TrialBalanceCommand extends Command
{
    protected $signature = 'ledger:trial-balance
        {currency : ISO currency code, e.g. USD}
        {--as-of= : Include entries up to the end of this date (default: now)}';

    protected $description = 'Show account balances in one currency and check that debits equal credits';

    public function handle(Ledger $ledger): int
    {
        $argument = $this->argument('currency');
        $currency = strtoupper(is_string($argument) ? $argument : '');
        $asOfOption = $this->option('as-of');
        $asOf = is_string($asOfOption) ? Carbon::parse($asOfOption)->endOfDay() : now();

        $tb = $ledger->trialBalance($currency, $asOf);

        $rows = array_map(
            fn (array $r) => [$r['code'], $r['name'], $r['type'], $this->money($r['debit']), $this->money($r['credit'])],
            $tb['rows'],
        );
        $rows[] = ['', 'Total', '', $this->money($tb['debits']), $this->money($tb['credits'])];
        $this->table(['Code', 'Account', 'Type', 'Debit', 'Credit'], $rows);

        $label = "Trial balance for {$currency} as of {$asOf->toDateString()}";
        if ($tb['debits'] === $tb['credits']) {
            $this->info("{$label}: debits equal credits.");

            return self::SUCCESS;
        }

        $this->error("{$label}: debits {$this->money($tb['debits'])} and credits {$this->money($tb['credits'])} differ. Run ledger:verify.");

        return self::FAILURE;
    }

    // shortcut: assumes two decimal places; currencies like JPY (0) or KWD (3) print shifted, add a minor-unit table when needed.
    private function money(int $minor): string
    {
        return $minor === 0 ? '' : number_format($minor / 100, 2);
    }
}
