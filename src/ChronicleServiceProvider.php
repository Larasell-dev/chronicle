<?php

declare(strict_types=1);

namespace Larasell\Chronicle;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\ServiceProvider;
use Larasell\Chronicle\WideEvents\Outputs\PostHogHandler;
use Larasell\Chronicle\WideEvents\Requests\RequestLogger;
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
                $handler = new PostHogHandler(
                    host: (string) ($config['host'] ?? 'https://us.i.posthog.com'),
                    apiKey: (string) ($config['api_key'] ?? ''),
                    http: $app->make(HttpFactory::class),
                    service: (string) config('app.name', 'laravel'),
                    scope: $config['scope'] ?? 'chronicle',
                    level: $config['level'] ?? Logger::INFO,
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

                return new Logger($app->environment(), [$handler]);
            });

            return $logger;
        });
    }
}
