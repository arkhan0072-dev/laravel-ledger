<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The accounting date of an entry, which can differ from when it was recorded (created_at). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_journal_entries', function (Blueprint $table) {
            $table->timestamp('posted_at')->nullable()->index();
        });

        DB::table('ledger_journal_entries')->whereNull('posted_at')->update(['posted_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('ledger_journal_entries', function (Blueprint $table) {
            $table->dropIndex(['posted_at']);
            $table->dropColumn('posted_at');
        });
    }
};
