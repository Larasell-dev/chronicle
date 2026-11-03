<?php

namespace Larasell\Chronicle\Tests;

use Illuminate\Foundation\Application;
use Larasell\Chronicle\ChronicleServiceProvider;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
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

    /**
     * Defines every channel the tests use, so tests pick a channel through chronicle.default
     * instead of defining one. All test channels write to one TestHandler per application, which
     * chronicleTestHandler() returns.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // A log file of the test's own, never the one in storage/: that one outlives the test,
        // and every run and process shares it, so a test could read lines another one wrote.
        $logPath = sys_get_temp_dir().'/chronicle-test-'.bin2hex(random_bytes(8)).'.log';
        config()->set('chronicle.channels.file.path', $logPath);
        $this->beforeApplicationDestroyed(fn () => $this->deleteIfExists($logPath));

        $app->singleton(TestHandler::class);

        config()->set('chronicle.channels.test', [
            'driver' => 'custom',
            'via' => fn (): Logger => new Logger('test', [$app->make(TestHandler::class)]),
        ]);

        // An app channel on the same handler, so the app's own log lines and Chronicle's events
        // can be compared.
        config()->set('logging.channels.app_test', [
            'driver' => 'custom',
            'via' => fn (): Logger => new Logger('app_test', [$app->make(TestHandler::class)]),
        ]);
    }

    private function deleteIfExists(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
