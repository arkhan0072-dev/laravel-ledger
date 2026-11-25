<?php

namespace Arkhan\Ledger\Models;

use Arkhan\Ledger\Exceptions\ImmutableRecord;

/**
 * Blocks updates and deletes through Eloquent.
 *
 * shortcut: raw SQL (DB::table) bypasses this; add database triggers or revoke UPDATE/DELETE when that matters.
 */
trait Immutable
{
    protected static function bootImmutable(): void
    {
        static::updating(fn () => throw new ImmutableRecord);
        static::deleting(fn () => throw new ImmutableRecord);
    }
}
