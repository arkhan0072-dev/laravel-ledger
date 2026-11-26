<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Kept apart from tests that post entries: PostgreSQL refuses DDL while commit-time checks are pending.

it('drops the entry index when rolled back', function () {
    if (DB::getDriverName() === 'mysql') {
        $this->markTestSkipped('MySQL keeps its own foreign key index and commits DDL immediately.');
    }
    $index = require __DIR__.'/../../database/migrations/2026_11_27_000002_index_ledger_entry_lines_by_entry.php';

    $index->down();
    $dropped = ! Schema::hasIndex('ledger_entry_lines', ['journal_entry_id']);
    $index->up();

    expect($dropped)->toBeTrue();
});
