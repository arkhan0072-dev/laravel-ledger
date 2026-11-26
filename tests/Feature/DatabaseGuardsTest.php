<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// These guards hold even for code that skips the package and writes SQL directly.

beforeEach(function () {
    $cash = Account::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset, 'currency' => 'USD']);
    $sales = Account::create(['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income, 'currency' => 'USD']);
    $this->cash = $cash;
    $this->sales = $sales;
    $this->entry = app(Ledger::class)->post('Sale', [Line::debit($cash, 100), Line::credit($sales, 100)]);
});

it('refuses raw SQL that changes a posted line', function () {
    DB::table('ledger_entry_lines')->where('journal_entry_id', $this->entry->id)->update(['debit' => 999]);
})->throws(QueryException::class, 'immutable');

it('refuses raw SQL that deletes a posted line', function () {
    DB::table('ledger_entry_lines')->where('journal_entry_id', $this->entry->id)->delete();
})->throws(QueryException::class, 'immutable');

it('refuses raw SQL that changes a posted entry', function () {
    DB::table('ledger_journal_entries')->where('id', $this->entry->id)->update(['description' => 'Edited']);
})->throws(QueryException::class, 'immutable');

it('refuses raw SQL that deletes a posted entry', function () {
    DB::table('ledger_journal_entries')->where('id', $this->entry->id)->delete();
})->throws(QueryException::class, 'immutable');

// PostgreSQL checks each entry at commit. The tests never commit, so they ask for the check now.
describe('on PostgreSQL', function () {
    beforeEach(function () {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Commit-time balance check is PostgreSQL only.');
        }
    });

    it('commits a balanced entry', function () {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        expect(true)->toBeTrue();
    });

    it('refuses to commit an entry whose lines do not balance', function () {
        $id = DB::table('ledger_journal_entries')->insertGetId(['description' => 'Raw', 'currency' => 'USD', 'posted_at' => now()]);
        DB::table('ledger_entry_lines')->insert([
            ['journal_entry_id' => $id, 'account_id' => $this->cash->id, 'debit' => 100, 'credit' => 0],
            ['journal_entry_id' => $id, 'account_id' => $this->sales->id, 'debit' => 0, 'credit' => 90],
        ]);

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    })->throws(QueryException::class, 'does not balance');

    it('refuses to commit an entry with fewer than two lines', function () {
        DB::table('ledger_journal_entries')->insert(['description' => 'Raw', 'currency' => 'USD', 'posted_at' => now()]);

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    })->throws(QueryException::class, 'at least two lines');
});

describe('on MySQL without trigger rights', function () {
    beforeEach(function () {
        $this->runGuards = function (int $mysqlError) {
            $error = new PDOException('MySQL refused');
            $error->errorInfo = ['HY000', $mysqlError, 'MySQL refused'];
            DB::partialMock()->shouldReceive('getDriverName')->andReturn('mysql');
            DB::partialMock()->shouldReceive('unprepared')->andThrow(new QueryException('mysql', 'CREATE TRIGGER', [], $error));
            Log::spy();

            (require __DIR__.'/../../database/migrations/2026_11_27_000001_add_ledger_database_guards.php')->up();
        };
    });

    it('installs without the guards and says how to enable them', function () {
        ($this->runGuards)(1419);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'log_bin_trust_function_creators'));
    });

    it('still fails on any other error', function () {
        ($this->runGuards)(1064);
    })->throws(QueryException::class, 'MySQL refused');
});

it('removes the guards when rolled back', function () {
    if (DB::getDriverName() === 'mysql') {
        $this->markTestSkipped('MySQL commits DDL immediately, which would leak out of the test transaction.');
    }
    $guards = require __DIR__.'/../../database/migrations/2026_11_27_000001_add_ledger_database_guards.php';

    $guards->down();
    DB::table('ledger_journal_entries')->where('id', $this->entry->id)->update(['description' => 'Edited']);
    $guards->up();

    expect(DB::table('ledger_journal_entries')->where('id', $this->entry->id)->value('description'))->toBe('Edited');
});
