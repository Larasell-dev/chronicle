<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
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
     * Defines every channel the tests use. Chronicle registers its channels once at boot, and this
     * runs before that, so tests pick a channel through chronicle.default instead of defining one.
     * All test channels write to one TestHandler per application, which chronicleTestHandler()
     * returns.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // No test may reach a real server, PostHog included.
        Http::preventStrayRequests();

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
        config()->set('chronicle.channels.posthog.api_key', 'phc_test');
        config()->set('chronicle.channels.posthog_without_key', [
            'driver' => 'posthog',
            'api_key' => null,
        ]);

        // An app channel on the same handler, so the app's own log lines and Chronicle's events
        // can be compared.
        config()->set('logging.channels.app_test', [
            'driver' => 'custom',
            'via' => fn (): Logger => new Logger('app_test', [$app->make(TestHandler::class)]),
        ]);
    }

    /**
     * Sends PHP's error log, where Chronicle reports what it couldn't deliver, to a file of this
     * test's own, and returns its path. error_log is a setting of the whole process, so it is set
     * back, and the file deleted, when the test ends.
     */
    public function captureErrorLog(): string
    {
        $path = sys_get_temp_dir().'/chronicle-error-'.bin2hex(random_bytes(8)).'.log';
        $previous = ini_set('error_log', $path);

        $this->beforeApplicationDestroyed(function () use ($path, $previous): void {
            ini_set('error_log', $previous === false ? '' : $previous);
            $this->deleteIfExists($path);
        });

        return $path;
    }

    private function deleteIfExists(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
