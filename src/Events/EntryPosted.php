<?php

namespace Arkhan\Ledger\Events;

use Arkhan\Ledger\Models\JournalEntry;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** A new entry was stored. Fired once the surrounding transaction commits; never for an idempotent retry. */
final class EntryPosted implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly JournalEntry $entry) {}
}
