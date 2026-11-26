<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Money reserved on an account but not yet moved, like a card authorization. Not an accounting entry:
// a capture posts the real transfer, and the hold keeps a link to it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('description');
            $table->string('status', 16)->default('active'); // active, captured, released
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('ledger_journal_entries')->restrictOnDelete();
            $table->timestamps();
            $table->index(['account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_holds');
    }
};
