<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Guards in the database itself, so raw SQL and other applications sharing the
 * database cannot rewrite history either:
 *  - posted entries and lines cannot be updated or deleted (all drivers);
 *  - on PostgreSQL, an entry that does not balance cannot be committed.
 */
return new class extends Migration
{
    private const TABLES = ['ledger_journal_entries', 'ledger_entry_lines'];

    public function up(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => $this->postgres(),
            'mysql', 'mariadb' => $this->mysql(),
            'sqlite' => $this->sqlite(),
            default => null, // other drivers rely on the package's own checks
        };
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // CASCADE drops the triggers that use the functions.
            DB::unprepared('DROP FUNCTION IF EXISTS ledger_immutable() CASCADE; DROP FUNCTION IF EXISTS ledger_check_balanced() CASCADE;');

            return;
        }
        foreach (self::TABLES as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
        }
    }

    private function postgres(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Rows in % are immutable; post a reversal instead.', TG_TABLE_NAME;
            END $$;

            CREATE OR REPLACE FUNCTION ledger_check_balanced() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                entry_id bigint;
                line_count bigint;
                debits numeric;
                credits numeric;
            BEGIN
                IF TG_TABLE_NAME = 'ledger_entry_lines' THEN
                    entry_id := NEW.journal_entry_id;
                ELSE
                    entry_id := NEW.id;
                END IF;
                SELECT count(*), coalesce(sum(debit), 0), coalesce(sum(credit), 0)
                  INTO line_count, debits, credits
                  FROM ledger_entry_lines WHERE journal_entry_id = entry_id;
                IF line_count < 2 THEN
                    RAISE EXCEPTION 'Entry #% needs at least two lines, has %.', entry_id, line_count;
                END IF;
                IF debits <> credits THEN
                    RAISE EXCEPTION 'Entry #% does not balance: debits %, credits %.', entry_id, debits, credits;
                END IF;
                RETURN NULL;
            END $$;
            SQL);

        foreach (self::TABLES as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION ledger_immutable()");
            // Deferred: lines are inserted after their entry, so check once the whole transaction is in.
            DB::unprepared("CREATE CONSTRAINT TRIGGER {$table}_balanced AFTER INSERT ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger_check_balanced()");
        }
    }

    private function mysql(): void
    {
        try {
            foreach (self::TABLES as $table) {
                foreach (['update', 'delete'] as $op) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_{$op} BEFORE ".strtoupper($op)." ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rows in {$table} are immutable; post a reversal instead.'");
                }
            }
        } catch (QueryException $e) {
            // 1419: binary logging is on and the user lacks SUPER, as on most managed MySQL. Install anyway; the package's own checks still apply.
            if (($e->errorInfo[1] ?? null) !== 1419) {
                throw $e;
            }
            Log::warning('laravel-ledger: MySQL immutability triggers were not installed because this database user may not create triggers while binary logging is on. Set log_bin_trust_function_creators=1 (or grant SUPER) and run the ledger guards migration again to enable them.');
        }
    }

    private function sqlite(): void
    {
        foreach (self::TABLES as $table) {
            foreach (['update', 'delete'] as $op) {
                DB::unprepared("CREATE TRIGGER {$table}_no_{$op} BEFORE ".strtoupper($op)." ON {$table} BEGIN SELECT RAISE(ABORT, 'Rows in {$table} are immutable; post a reversal instead.'); END");
            }
        }
    }
};
