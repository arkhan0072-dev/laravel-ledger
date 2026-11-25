<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type', 16);
            $table->char('currency', 3);
            $table->timestamps();
        });

        Schema::create('ledger_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('description');
            $table->char('currency', 3);
            $table->string('idempotency_key')->nullable()->unique();
            $table->char('request_hash', 64)->nullable();
            // Unique: an entry can be reversed at most once, even under concurrent requests.
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('ledger_journal_entries')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('ledger_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('ledger_journal_entries')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('debit')->default(0);
            $table->unsignedBigInteger('credit')->default(0);
            // PostgreSQL does not index foreign keys; balance() sums lines by account.
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entry_lines');
        Schema::dropIfExists('ledger_journal_entries');
        Schema::dropIfExists('ledger_accounts');
    }
};
