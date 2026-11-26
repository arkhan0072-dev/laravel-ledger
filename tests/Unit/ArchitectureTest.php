<?php

// laravel-ledger works without laravel-zatca: they only meet in the README recipe.
arch('does not depend on laravel-zatca')
    ->expect('Arkhan\Ledger')
    ->not->toUse('Arkhan\Zatca');
