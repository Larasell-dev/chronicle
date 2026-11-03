<?php

namespace Larasell\Chronicle;

use Illuminate\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Larasell\Chronicle\WideEvents\Outputs\PostHogHandler;
use Larasell\Chronicle\WideEvents\Requests\RequestLogger;
use Monolog\Logger;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;

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
                    scope: $config['scope'] ?? 'chronicle',
                    level: $config['level'] ?? Logger::INFO,
                    provider: $app->has(LoggerProviderInterface::class) ? $app->make(LoggerProviderInterface::class) : null,
                    debug: $app->hasDebugModeEnabled(),
                );

                return new Logger($app->environment(), [$handler]);
            });

            return $logger;
        });
    }
}
