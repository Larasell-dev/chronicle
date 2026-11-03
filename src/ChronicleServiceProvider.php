<?php

declare(strict_types=1);

namespace Larasell\Chronicle;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Larasell\Chronicle\Exceptions\StructuredInvalidArgumentException;
use Larasell\Chronicle\Exceptions\StructuredLogicException;
use Larasell\Chronicle\Support\Assert;
use Larasell\Chronicle\WideEvents\Chronicle;
use Larasell\Chronicle\WideEvents\Jobs\JobLogger;
use Larasell\Chronicle\WideEvents\Outputs\PostHogHandler;
use Larasell\Chronicle\WideEvents\Redactor;
use Larasell\Chronicle\WideEvents\Requests\Exceptions\InvalidSampler;
use Larasell\Chronicle\WideEvents\Requests\RequestLogger;
use Larasell\Chronicle\WideEvents\Requests\Sampler;
use Monolog\Level;
use Monolog\Logger;

class ChronicleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/chronicle.php', 'chronicle');

        $this->registerLoggingDrivers();

        $this->app->singleton(
            Chronicle::class,
            fn (Container $app): Chronicle => new Chronicle($this->redactedKeys(), $this->redactor($app)),
        );
        // One per process, so a failed check is reported once, not on every event.
        $this->app->singleton(Assert::class);
        $this->app->singleton(
            RequestLogger::class,
            fn (Container $app): RequestLogger => new RequestLogger($app->make(Chronicle::class), $this->sampler($app)),
        );

        // Before any provider boots, so another provider's boot() can already log through
        // Chronicle, and after the app's config, Testbench's defineEnvironment() included, is set.
        $this->app->booting(fn () => $this->registerChannels());

        // Always listening, since chronicle.log_jobs is read when a job begins. Before any provider
        // boots, so the job's event is written before the posthog channel's listener flushes it.
        $this->app->singleton(JobLogger::class);
        $this->app->booting(fn () => $this->listenToJobs());

        // Once every provider booted, since an app's sampler may depend on them. Building the
        // RequestLogger now resolves the sampler, so a broken chronicle.sampler stops the app at
        // boot, rather than failing every request that LogRequests logs.
        $this->app->booted(fn () => $this->app->make(RequestLogger::class));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/chronicle.php' => $this->app->configPath('chronicle.php'),
        ], 'chronicle.config');
    }

    /**
     * Registers Chronicle's channels with Laravel's log manager. They live in config/chronicle.php,
     * so they get a chronicle- prefix, which keeps them apart from the app's own channels. A
     * stack's channel names point to Chronicle's channels when one has that name, and to the app's
     * channels otherwise. Laravel caches a logger on first use, so this runs once: changing
     * chronicle.channels later has no effect, which is why nothing pretends otherwise.
     */
    protected function registerChannels(): void
    {
        $channels = Config::array('chronicle.channels', []);

        foreach (array_keys($channels) as $name) {
            $channel = Arr::array($channels, $name);

            if (($channel['driver'] ?? null) === 'stack') {
                $members = Arr::array($channel, 'channels', []);

                $channel['channels'] = array_map(
                    function (int|string $index) use ($members, $channels): string {
                        $member = Arr::string($members, $index);

                        return isset($channels[$member]) ? 'chronicle-'.$member : $member;
                    },
                    array_keys($members),
                );
            }

            Config::set('logging.channels.chronicle-'.$name, $channel);
        }
    }

    protected function listenToJobs(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $events->listen(JobProcessing::class, [JobLogger::class, 'processing']);
        $events->listen(JobProcessed::class, [JobLogger::class, 'processed']);
        $events->listen(JobExceptionOccurred::class, [JobLogger::class, 'exceptionOccurred']);
    }

    /**
     * The configured sampler, resolved once, so a sampler that keeps state across requests works
     * wherever the process lives on, e.g. under Octane. Null keeps every request event.
     */
    protected function sampler(Container $app): ?Sampler
    {
        // The old name of chronicle.sampler. The package config no longer has it, so only a published config
        // can set it. Ignoring it would silently keep every request event.
        if (config('chronicle.sampling') !== null) {
            throw new StructuredLogicException(
                errorCode: 'chronicle.sampling.renamed',
                message: 'chronicle.sampling is now chronicle.sampler',
                why: 'config/chronicle.php still sets sampling, which Chronicle no longer reads',
                fix: "Rename 'sampling' to 'sampler' in config/chronicle.php",
            );
        }

        if (config('chronicle.sampler') === null) {
            return null;
        }

        $sampler = $app->make(Config::string('chronicle.sampler'));

        if (! $sampler instanceof Sampler) {
            throw InvalidSampler::doesNotImplementSampler($sampler);
        }

        return $sampler;
    }

    /**
     * The keys of chronicle.redact. An entry that isn't a non-empty string would match no key, or
     * every key, so it stops the app at boot rather than silently logging what it should hide.
     *
     * @return list<string>
     */
    protected function redactedKeys(): array
    {
        $keys = Config::array('chronicle.redact', []);

        foreach ($keys as $key) {
            if (is_string($key) && trim($key) !== '') {
                continue;
            }

            throw new StructuredInvalidArgumentException(
                errorCode: 'chronicle.redact.invalid',
                message: 'chronicle.redact must only list keys, found '.get_debug_type($key),
                why: 'Chronicle redacts the values of keys that contain one of the listed keys as whole words',
                fix: 'Make every entry of redact in config/chronicle.php a non-empty string, e.g. \'password\'',
            );
        }

        return array_values($keys);
    }

    /**
     * The configured redactor, resolved once like the sampler. Null logs values as they are.
     */
    protected function redactor(Container $app): ?Redactor
    {
        if (config('chronicle.redactor') === null) {
            return null;
        }

        $redactor = $app->make(Config::string('chronicle.redactor'));

        if (! $redactor instanceof Redactor) {
            throw new StructuredLogicException(
                errorCode: 'chronicle.redactor.invalid',
                message: 'chronicle.redactor must resolve to an instance of '.Redactor::class,
                why: 'chronicle.redactor resolved to '.get_debug_type($redactor),
                fix: 'Implement the '.Redactor::class.' contract or remove the chronicle.redactor config',
            );
        }

        return $redactor;
    }

    protected function registerLoggingDrivers(): void
    {
        $this->app->extend('log', function (LogManager $logger): LogManager {
            $logger->extend('posthog', function (Application $app, array $config): Logger {
                // An unset POSTHOG_API_KEY is null; the handler treats an empty key as missing.
                $apiKey = $config['api_key'] ?? '';
                $level = strtoupper(Arr::string($config, 'level', PostHogHandler::LEVEL_DEFAULT));

                if (! is_string($apiKey)) {
                    throw new InvalidArgumentException('The api_key of the posthog channel must be a string.');
                }

                if (! in_array($level, Level::NAMES, true)) {
                    throw new InvalidArgumentException("The level of the posthog channel must be a log level, [{$level}] given.");
                }

                $handler = new PostHogHandler(
                    host: Arr::string($config, 'host', PostHogHandler::HOST_DEFAULT),
                    apiKey: $apiKey,
                    http: $app->make(HttpFactory::class),
                    service: Config::string('app.name', 'laravel'),
                    scope: Arr::string($config, 'scope', PostHogHandler::SCOPE_DEFAULT),
                    level: Level::fromName($level),
                    debug: $app->hasDebugModeEnabled(),
                );

                // Laravel builds a channel's logger once per process, so these register once.
                // Terminating runs after the response is sent; queue workers never terminate
                // between jobs, so they send after each job instead.
                $app->terminating(fn () => $handler->flush());
                $app->make(Dispatcher::class)->listen(
                    // JobExceptionOccurred too, so the event of an attempt that fails and is retried
                    // is sent now rather than after the next job.
                    [JobProcessed::class, JobExceptionOccurred::class, JobFailed::class],
                    fn () => $handler->flush(),
                );

                return new Logger(Config::string('app.env', 'production'), [$handler]);
            });

            return $logger;
        });
    }
}
