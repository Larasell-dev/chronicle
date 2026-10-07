<?php

namespace Larasell\Chronicle;

use Illuminate\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Larasell\Chronicle\Logging\ChronicleFormatter;
use Larasell\Chronicle\Logging\PostHogHandler;
use Monolog\Handler\StreamHandler;
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
            $logger->extend('chronicle', function (Application $app, array $config): Logger {
                $handler = new StreamHandler(
                    $config['path'] ?? $app->storagePath('logs/chronicle.log'),
                    $config['level'] ?? Logger::INFO,
                );

                $handler->setFormatter(new ChronicleFormatter);

                return new Logger($app->environment(), [$handler]);
            });

            $logger->extend('posthog', function (Application $app, array $config): Logger {
                $handler = new PostHogHandler(
                    host: $config['host'] ?? (string) config('chronicle.posthog.host'),
                    apiKey: $config['api_key'] ?? (string) config('chronicle.posthog.api_key'),
                    scope: $config['scope'] ?? 'chronicle',
                    level: $config['level'] ?? Logger::INFO,
                    provider: $app->has(LoggerProviderInterface::class) ? $app->make(LoggerProviderInterface::class) : null,
                );

                return new Logger($app->environment(), [$handler]);
            });

            $channels = ['chronicle.file'];

            if (config('chronicle.posthog.enabled', false)) {
                $channels[] = 'posthog';
            }

            config()->set('logging.channels.chronicle', config('logging.channels.chronicle') ?? [
                'driver' => 'stack',
                'channels' => $channels,
            ]);

            config()->set('logging.channels.posthog', config('logging.channels.posthog') ?? [
                'driver' => 'posthog',
                'level' => 'info',
            ]);

            config()->set('logging.channels.chronicle.file', config('logging.channels.chronicle.file') ?? [
                'driver' => 'chronicle',
                'level' => 'info',
            ]);

            return $logger;
        });
    }
}
