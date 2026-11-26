<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per close or reopen; the latest row is the lock, the rest is its history.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_period_closes', function (Blueprint $table) {
            $table->id();
            $table->date('closed_through')->nullable(); // null: every period open
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_period_closes');
    }
};
