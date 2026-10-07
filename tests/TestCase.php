<?php

namespace Larasell\Chronicle\Tests;

use Illuminate\Foundation\Application;
use Larasell\Chronicle\ChronicleServiceProvider;
use Orchestra\Testbench\TestCase as TestbenchTestCase;

abstract class TestCase extends TestbenchTestCase
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ChronicleServiceProvider::class];
    }
}
