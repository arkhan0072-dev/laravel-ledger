<?php

namespace Arkhan\Ledger\Tests;

use Arkhan\Ledger\LedgerServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [LedgerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // DB_CONNECTION=pgsql in CI runs the same tests against PostgreSQL.
        $app['config']->set('database.default', env('DB_CONNECTION', 'testing'));
    }
}
