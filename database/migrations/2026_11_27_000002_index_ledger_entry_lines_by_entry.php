<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PostgreSQL and SQLite do not index foreign keys; MySQL already has one for the constraint.
// Without it, loading an entry's lines and the PostgreSQL commit-time check scan every line.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('ledger_entry_lines', ['journal_entry_id'])) {
            Schema::table('ledger_entry_lines', fn (Blueprint $table) => $table->index('journal_entry_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('ledger_entry_lines', 'ledger_entry_lines_journal_entry_id_index')) {
            Schema::table('ledger_entry_lines', fn (Blueprint $table) => $table->dropIndex(['journal_entry_id']));
        }
    }
};
