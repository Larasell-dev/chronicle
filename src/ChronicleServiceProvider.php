<?php

namespace Larasell\Chronicle;

use Illuminate\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Larasell\Chronicle\Logging\ChronicleFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class ChronicleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/chronicle.php', 'chronicle');

        $this->registerLoggingChannel();

        $this->app->singleton(Chronicle::class);
        $this->app->singleton(RequestLogger::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/chronicle.php' => $this->app->configPath('chronicle.php'),
        ], 'chronicle.config');
    }

    protected function registerLoggingChannel(): void
    {
        $this->app->extend('log', function (LogManager $logger): LogManager {
            $logger->extend('chronicle', function (Application $app, array $config): Logger {
                $handler = new StreamHandler(
                    $config['path'] ?? $app->storagePath('logs/chronicle.log'),
                    $config['level'] ?? Logger::INFO,
                );

                $handler->setFormatter(new ChronicleFormatter);

                return new Logger($this->app->environment(), [$handler]);
            });

            config()->set('logging.channels.chronicle', config('logging.channels.chronicle') ?? [
                'driver' => 'chronicle',
                'level' => 'info',
            ]);

            return $logger;
        });
    }
}
