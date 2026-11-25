<?php

namespace Arkhan\Ledger\Console;

use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Models\JournalEntry;
use Illuminate\Console\Command;

class VerifyCommand extends Command
{
    protected $signature = 'ledger:verify';

    protected $description = 'Check that every journal entry balances and every line is valid';

    public function handle(Ledger $ledger): int
    {
        $problems = $ledger->verify();

        if ($problems === []) {
            $this->info('Ledger OK: '.JournalEntry::count().' entries checked.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }
        $this->error('Ledger has '.count($problems).' problem(s).');

        return self::FAILURE;
    }
}
