<?php

namespace Arkhan\Ledger\Events;

use Arkhan\Ledger\Models\JournalEntry;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** $reversal was posted to cancel $original. Fired once the surrounding transaction commits. */
final class EntryReversed implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly JournalEntry $reversal, public readonly JournalEntry $original) {}
}
