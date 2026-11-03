<?php

declare(strict_types=1);

namespace Larasell\Chronicle;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Larasell\Chronicle\WideEvents\Outputs\PostHogHandler;
use Larasell\Chronicle\WideEvents\Requests\RequestLogger;
use Monolog\Level;
use Monolog\Logger;

class ChronicleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/chronicle.php', 'chronicle');

        $this->registerLoggingDrivers();

        $this->app->singleton(Chronicle::class);
        $this->app->singleton(RequestLogger::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/chronicle.php' => $this->app->configPath('chronicle.php'),
        ], 'chronicle.config');
    }

    protected function registerLoggingDrivers(): void
    {
        $this->app->extend('log', function (LogManager $logger): LogManager {
            $logger->extend('posthog', function (Application $app, array $config): Logger {
                // An unset POSTHOG_API_KEY is null; the handler treats an empty key as missing.
                $apiKey = $config['api_key'] ?? '';
                $level = strtoupper(Arr::string($config, 'level', 'info'));

                if (! is_string($apiKey)) {
                    throw new InvalidArgumentException('The api_key of the posthog channel must be a string.');
                }

                if (! in_array($level, Level::NAMES, true)) {
                    throw new InvalidArgumentException("The level of the posthog channel must be a log level, [{$level}] given.");
                }

                $handler = new PostHogHandler(
                    host: Arr::string($config, 'host', 'https://us.i.posthog.com'),
                    apiKey: $apiKey,
                    http: $app->make(HttpFactory::class),
                    service: Config::string('app.name', 'laravel'),
                    scope: Arr::string($config, 'scope', 'chronicle'),
                    level: Level::fromName($level),
                    debug: $app->hasDebugModeEnabled(),
                );

                // Laravel builds a channel's logger once per process, so these register once.
                // Terminating runs after the response is sent; queue workers never terminate
                // between jobs, so they send after each job instead.
                $app->terminating(fn () => $handler->flush());
                $app->make(Dispatcher::class)->listen(
                    [JobProcessed::class, JobFailed::class],
                    fn () => $handler->flush(),
                );

                return new Logger(Config::string('app.env', 'production'), [$handler]);
            });

            return $logger;
        });
    }
}
