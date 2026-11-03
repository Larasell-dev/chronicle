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
            fn (Container $app): Chronicle => new Chronicle($this->redactedKeys(), $this->redactor($app), $this->globalFields()),
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

    /**
     * The fields of every event, named as OpenTelemetry's resource attributes, read once at boot
     * like the channels: Chronicle's own, then chronicle.fields. Anything malformed stops the app
     * at boot, rather than silently logging events that can't be told apart.
     *
     * @return array<string, scalar>
     */
    protected function globalFields(): array
    {
        // Null ones included, so the app can't set service.version through fields either.
        $chronicleFields = [
            'service.name' => $this->optionalString('service') ?? Config::string('app.name', 'laravel'),
            'service.version' => $this->optionalString('release'),
            'deployment.environment' => $this->optionalString('environment') ?? Config::string('app.env', 'production'),
            'host.name' => $this->hostName(),
            'process.runtime.version' => PHP_VERSION,
        ];
        $appFields = $this->appFields();

        foreach (array_keys($appFields) as $key) {
            if (array_key_exists($key, $chronicleFields)) {
                throw $this->invalidFields("chronicle.fields sets [{$key}], which Chronicle sets itself");
            }
        }

        $chronicleFields = array_filter($chronicleFields, fn (?string $value): bool => $value !== null);
        $fieldCount = count($chronicleFields) + count($appFields);

        // Every event carries them, so they take room from the fields that describe the event.
        if ($fieldCount > Chronicle::GLOBAL_FIELDS_MAX) {
            throw $this->invalidFields("Chronicle has {$fieldCount} global fields, more than ".Chronicle::GLOBAL_FIELDS_MAX);
        }

        return [...$chronicleFields, ...$appFields];
    }

    /**
     * host.name: CHRONICLE_HOST, or else the machine's name. An empty CHRONICLE_HOST= turns it
     * off, so unlike the other options, empty doesn't mean unset here.
     */
    protected function hostName(): ?string
    {
        if (config('chronicle.host') === '') {
            return null;
        }

        $host = $this->optionalString('host');

        if ($host !== null) {
            return $host;
        }

        $machineName = gethostname();

        // An operating error, not a bug: the event is still worth writing without the host.
        if ($machineName === false) {
            return null;
        }

        if ($machineName === '') {
            return null;
        }

        return $machineName;
    }

    /**
     * chronicle.fields, validated: an array, or a string as CHRONICLE_FIELDS sets it.
     *
     * @return array<string, scalar>
     */
    protected function appFields(): array
    {
        $configured = config('chronicle.fields') ?? [];

        if (is_string($configured)) {
            return $this->parsedFields($configured);
        }

        if (! is_array($configured)) {
            throw $this->invalidFields('chronicle.fields must be an array or a string of key=value pairs, found '.get_debug_type($configured));
        }

        foreach ($configured as $key => $value) {
            if (! is_string($key)) {
                throw $this->invalidFields("chronicle.fields must map keys to values, found the key [{$key}]");
            }

            if (trim($key) === '') {
                throw $this->invalidFields('chronicle.fields has an empty key');
            }

            if (! is_scalar($value)) {
                throw $this->invalidFields('chronicle.fields must map each key to a string, number or bool, found '.get_debug_type($value)." for [{$key}]");
            }

            // JSON has no NAN or INF, so every event would report the field as unencodable.
            if (is_float($value)) {
                if (! is_finite($value)) {
                    throw $this->invalidFields("chronicle.fields must map [{$key}] to a finite number, found {$value}");
                }
            }
        }

        return $configured;
    }

    /**
     * The fields of a string in OpenTelemetry's format, as OTEL_RESOURCE_ATTRIBUTES has them:
     * key=value pairs separated by commas, with spaces around them ignored and the values
     * percent-decoded, so a value can hold a comma as %2C.
     *
     * @return array<string, string>
     */
    protected function parsedFields(string $fields): array
    {
        if (trim($fields) === '') {
            return [];
        }

        $pairs = explode(',', $fields);

        // Checked before parsing, so the loop below is bounded too.
        if (count($pairs) > Chronicle::GLOBAL_FIELDS_MAX) {
            throw $this->invalidFields('CHRONICLE_FIELDS has '.count($pairs).' fields, more than '.Chronicle::GLOBAL_FIELDS_MAX);
        }

        $parsed = [];

        foreach ($pairs as $pair) {
            $malformed = 'CHRONICLE_FIELDS must be key=value pairs separated by commas, found ['.trim($pair).']';
            $separatorIndex = strpos($pair, '=');

            if ($separatorIndex === false) {
                throw $this->invalidFields($malformed);
            }

            $key = trim(substr($pair, 0, $separatorIndex));
            $value = trim(substr($pair, $separatorIndex + 1));

            if ($key === '') {
                throw $this->invalidFields($malformed);
            }

            if ($value === '') {
                throw $this->invalidFields($malformed);
            }

            // A later pair would silently replace the earlier one.
            if (array_key_exists($key, $parsed)) {
                throw $this->invalidFields("CHRONICLE_FIELDS sets [{$key}] twice");
            }

            $parsed[$key] = rawurldecode($value);
        }

        return $parsed;
    }

    protected function invalidFields(string $message): StructuredInvalidArgumentException
    {
        return new StructuredInvalidArgumentException(
            errorCode: 'chronicle.fields.invalid',
            message: $message,
            why: 'Chronicle adds the global fields to every event, so it reads them once at boot',
            fix: 'Set CHRONICLE_FIELDS to e.g. region=eu,team=shop, or fields in config/chronicle.php to e.g. [\'region\' => \'eu\']',
        );
    }

    /**
     * A string option of config/chronicle.php, or null when it is unset or empty, so an empty
     * CHRONICLE_RELEASE= is the same as none.
     */
    protected function optionalString(string $key): ?string
    {
        $value = config("chronicle.{$key}");

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new StructuredInvalidArgumentException(
                errorCode: "chronicle.{$key}.invalid",
                message: "chronicle.{$key} must be a string, found ".get_debug_type($value),
                why: 'Chronicle adds it to every event as a global field',
                fix: "Set {$key} in config/chronicle.php to a string, or leave it null",
            );
        }

        if ($value === '') {
            return null;
        }

        return $value;
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
                    resource: $app->make(Chronicle::class)->loggedGlobalFields(),
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
