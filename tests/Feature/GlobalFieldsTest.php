<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Larasell\Chronicle\Exceptions\StructuredInvalidArgumentException;
use Larasell\Chronicle\Facades\Chronicle;
use Larasell\Chronicle\Tests\Fixtures\EmailMaskingRedactor;
use Larasell\Chronicle\Tests\Fixtures\ImportProducts;
use Larasell\Chronicle\WideEvents\Chronicle as WideEventsChronicle;
use Larasell\Chronicle\WideEvents\Requests\Middleware\LogRequests;
use Larasell\Chronicle\WideEvents\Requests\RequestLogger;

// How: Chronicle reads the global fields once at boot, so each test sets the config and builds
// Chronicle again with bootChronicleWith(), as the app's booted callback does, then reads the
// events from the test channel. Environment variables go through the real config file. The
// malformed inputs sit on both sides of each rule, valid inputs in the other tests.

/**
 * Sets the config and builds Chronicle again, as the app does once at boot: the booted callback
 * resolves the RequestLogger, and with it Chronicle.
 *
 * @param  array<string, mixed>  $config
 */
function bootChronicleWith(array $config): void
{
    config()->set($config);
    app()->forgetInstance(WideEventsChronicle::class);
    app()->forgetInstance(RequestLogger::class);
    Chronicle::clearResolvedInstance(WideEventsChronicle::class);
    app(RequestLogger::class);
}

it('adds the global fields to request, job and scope events', function () {
    // Arrange
    config()->set('app.name', 'Shop');
    config()->set('app.env', 'staging');
    config()->set('queue.default', 'sync');
    config()->set('chronicle.log_jobs', true);
    bootChronicleWith([]);
    $handler = chronicleTestHandler();

    Route::get('/ping', fn () => 'ok')->middleware(LogRequests::class);

    // Act
    $this->get('/ping');
    ImportProducts::dispatch();
    Chronicle::scope('import.run', fn () => null);

    // Assert
    $entries = chronicleEntries($handler);

    expect(array_column($entries, 'type'))->toBe(['request', 'job', 'import.run']);

    foreach ($entries as $entry) {
        expect($entry['service.name'])->toBe('Shop')
            ->and($entry['deployment.environment'])->toBe('staging')
            ->and($entry['host.name'])->toBe(gethostname())
            ->and($entry['process.runtime.version'])->toBe(PHP_VERSION)
            // No release unless the deploy sets one.
            ->and($entry)->not->toHaveKey('service.version');
    }
});

it('takes the global fields from the chronicle config', function () {
    // Arrange
    bootChronicleWith([
        'chronicle.service' => 'shop-api',
        'chronicle.release' => 'v1.4.2',
        'chronicle.environment' => 'eu-production',
        'chronicle.host' => 'web-3',
        'chronicle.fields' => ['region' => 'eu', 'shard' => 7, 'canary' => false],
    ]);
    $handler = chronicleTestHandler();

    // Act
    Chronicle::record('order.paid');

    // Assert
    expect(Arr::except(chronicleEntries($handler)[0], ['timestamp']))->toBe([
        'type' => 'order.paid',
        'service.name' => 'shop-api',
        'service.version' => 'v1.4.2',
        'deployment.environment' => 'eu-production',
        'host.name' => 'web-3',
        'process.runtime.version' => PHP_VERSION,
        'region' => 'eu',
        'shard' => 7,
        'canary' => false,
    ]);
});

it('reads the global fields from the environment', function (string $variable, string $value, string $key, array $expected) {
    // Arrange
    putenv("{$variable}={$value}");

    try {
        /** @var array<string, mixed> $config */
        $config = require __DIR__.'/../../config/chronicle.php';
    } finally {
        putenv($variable);
    }

    // Act
    bootChronicleWith(['chronicle.'.$key => $config[$key]]);
    $handler = chronicleTestHandler();
    Chronicle::record('order.paid');

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect(Arr::only($entry, array_keys($expected)))->toBe($expected);
})->with([
    'service' => ['CHRONICLE_SERVICE', 'shop-api', 'service', ['service.name' => 'shop-api']],
    'release' => ['CHRONICLE_RELEASE', '3f2a9c1', 'release', ['service.version' => '3f2a9c1']],
    'environment' => ['CHRONICLE_ENVIRONMENT', 'canary', 'environment', ['deployment.environment' => 'canary']],
    'host' => ['CHRONICLE_HOST', 'web-3', 'host', ['host.name' => 'web-3']],
    'fields' => ['CHRONICLE_FIELDS', 'region=eu, team = shop', 'fields', ['region' => 'eu', 'team' => 'shop']],
    // OpenTelemetry percent-encodes what the format itself uses, such as commas.
    'percent-encoded fields' => ['CHRONICLE_FIELDS', 'team=shop%2Ccheckout,owner=n%3Dh', 'fields', ['team' => 'shop,checkout', 'owner' => 'n=h']],
]);

it('leaves host name out when chronicle host is empty', function () {
    // Arrange
    putenv('CHRONICLE_HOST=');

    try {
        /** @var array<string, mixed> $config */
        $config = require __DIR__.'/../../config/chronicle.php';
    } finally {
        putenv('CHRONICLE_HOST');
    }

    bootChronicleWith(['chronicle.host' => $config['host']]);
    $handler = chronicleTestHandler();

    // Act
    Chronicle::record('order.paid');

    // Assert
    expect(chronicleEntries($handler)[0])->not->toHaveKey('host.name');
});

it('lets measured fields, context, added and recorded fields win over the global fields', function () {
    // Arrange
    bootChronicleWith([
        'chronicle.fields' => ['status' => 'green', 'route' => 'everywhere', 'type' => 'global', 'timestamp' => 'never', 'region' => 'eu', 'team' => 'shop'],
    ]);
    $handler = chronicleTestHandler();

    Route::get('/ping', function () {
        Context::add('region', 'us');
        Chronicle::add('deployment.environment', 'canary');

        return 'ok';
    })->middleware(LogRequests::class);

    // Act
    $this->get('/ping');
    Chronicle::record('order.paid', ['team' => 'checkout']);

    // Assert
    [$request, $recorded] = chronicleEntries($handler);

    expect($request['type'])->toBe('request')
        ->and($request['status'])->toBe(200)
        ->and($request['route'])->toBe('ping')
        ->and($request['timestamp'])->not->toBe('never')
        ->and($request['region'])->toBe('us')
        ->and($request['deployment.environment'])->toBe('canary')
        ->and($recorded['team'])->toBe('checkout');
});

it('redacts and bounds the global fields like any other field', function () {
    // Arrange
    bootChronicleWith([
        'chronicle.redactor' => EmailMaskingRedactor::class,
        'chronicle.fields' => ['deploy_token' => 'ghp_secret', 'owner' => 'nils@example.com', 'notes' => str_repeat('a', 2000)],
    ]);
    $handler = chronicleTestHandler();

    // Act
    Chronicle::record('order.paid');

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['deploy_token'])->toBe(WideEventsChronicle::REDACTED)
        ->and($entry['owner'])->toBe('***@example.com')
        ->and(json_decode(Arr::string($entry, 'chronicle.redacted'), true))->toBe(['deploy_token', 'owner'])
        ->and(strlen(Arr::string($entry, 'notes')))->toBe(WideEventsChronicle::VALUE_BYTES_MAX);
});

it('keeps the global fields when context fills the event past its field limit', function () {
    // Arrange
    bootChronicleWith(['chronicle.fields' => ['region' => 'eu']]);
    $handler = chronicleTestHandler();

    foreach (range(0, 999) as $index) {
        Context::add("context.f{$index}", $index);
    }

    // Act
    Chronicle::record('wide');

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry)->toHaveCount(256)
        ->and($entry['region'])->toBe('eu')
        ->and($entry['service.name'])->toBeString()
        ->and($entry['process.runtime.version'])->toBe(PHP_VERSION);
});

it('stops the app at boot when a global field is malformed', function (string $key, mixed $value, string $message) {
    // Act & Assert
    expect(fn () => bootChronicleWith(["chronicle.{$key}" => $value]))
        ->toThrow(StructuredInvalidArgumentException::class, $message);
})->with([
    'a field without a value' => ['fields', 'region', 'CHRONICLE_FIELDS must be key=value pairs separated by commas, found [region]'],
    'a field without a key' => ['fields', '=eu', 'CHRONICLE_FIELDS must be key=value pairs separated by commas, found [=eu]'],
    'an empty pair' => ['fields', 'region=eu,,team=shop', 'CHRONICLE_FIELDS must be key=value pairs separated by commas, found []'],
    'a list' => ['fields', ['eu'], 'chronicle.fields must map keys to values, found the key [0]'],
    'a nested array' => ['fields', ['team' => ['name' => 'shop']], 'chronicle.fields must map each key to a string, number or bool, found array for [team]'],
    'a field chronicle sets' => ['fields', ['service.name' => 'shop'], 'chronicle.fields sets [service.name], which Chronicle sets itself'],
    'a service that is not a string' => ['service', true, 'chronicle.service must be a string, found bool'],
    'a release that is not a string' => ['release', 42, 'chronicle.release must be a string, found int'],
    'an empty key' => ['fields', [' ' => 'eu'], 'chronicle.fields has an empty key'],
    'NAN' => ['fields', ['ratio' => NAN], 'chronicle.fields must map [ratio] to a finite number, found NAN'],
    'a key set twice' => ['fields', 'region=eu,region=us', 'CHRONICLE_FIELDS sets [region] twice'],
    'more pairs than the limit' => ['fields', implode(',', array_map(fn (int $index): string => "f{$index}=x", range(1, 129))), 'CHRONICLE_FIELDS has 129 fields, more than 128'],
]);

it('allows exactly 128 global fields, chronicle\'s own included', function (int $appFieldCount, bool $allowed) {
    // Arrange: five of Chronicle's own, since release and host are set.
    $fields = [];

    foreach (range(1, $appFieldCount) as $index) {
        $fields["f{$index}"] = $index;
    }

    $boot = fn () => bootChronicleWith([
        'chronicle.release' => 'v1',
        'chronicle.host' => 'web-3',
        'chronicle.fields' => $fields,
    ]);

    // Act & Assert
    if ($allowed) {
        $boot();
        $handler = chronicleTestHandler();
        Chronicle::record('order.paid');

        expect(chronicleEntries($handler)[0])->toHaveKey('f123');
    } else {
        expect($boot)->toThrow(StructuredInvalidArgumentException::class, 'Chronicle has 129 global fields, more than 128');
    }
})->with([
    'at the limit' => [123, true],
    'one over' => [124, false],
]);

it('keeps a service name of 0 rather than treating it as unset', function () {
    // Arrange
    bootChronicleWith(['chronicle.service' => '0']);
    $handler = chronicleTestHandler();

    // Act
    Chronicle::record('order.paid');

    // Assert
    expect(chronicleEntries($handler)[0]['service.name'])->toBe('0');
});

it('sends the global fields to posthog as resource attributes, not on every record', function () {
    // Arrange
    Http::fake();
    bootChronicleWith([
        'chronicle.service' => 'shop-api',
        'chronicle.release' => 'v1.4.2',
        'chronicle.environment' => 'production',
        'chronicle.host' => 'web-3',
        'chronicle.fields' => ['region' => 'eu'],
        'chronicle.default' => 'posthog',
    ]);

    // Act
    Chronicle::record('order.paid', ['order.id' => 7]);
    // A value of this event only, which the resource can't hold.
    Chronicle::record('order.paid', ['region' => 'us']);
    app()->terminate();

    // Assert
    $request = Arr::array(Http::recorded()->all(), 0)[0] ?? null;

    if (! $request instanceof Request) {
        throw new UnexpectedValueException('Chronicle sent nothing to PostHog.');
    }

    $payload = chronicleEntry($request->body());
    $attributeKeys = fn (array $record): array => array_column(Arr::array($record, 'attributes'), 'key');
    [$first, $second] = Arr::array($payload, 'resourceLogs.0.scopeLogs.0.logRecords');

    expect(Arr::get($payload, 'resourceLogs.0.resource.attributes'))->toBe([
        ['key' => 'service.name', 'value' => ['stringValue' => 'shop-api']],
        ['key' => 'service.version', 'value' => ['stringValue' => 'v1.4.2']],
        ['key' => 'deployment.environment', 'value' => ['stringValue' => 'production']],
        ['key' => 'host.name', 'value' => ['stringValue' => 'web-3']],
        ['key' => 'process.runtime.version', 'value' => ['stringValue' => PHP_VERSION]],
        ['key' => 'region', 'value' => ['stringValue' => 'eu']],
    ])
        ->and(is_array($first) ? $attributeKeys($first) : null)->toBe(['order.id'])
        ->and(is_array($second) ? Arr::array($second, 'attributes') : null)->toBe([
            ['key' => 'region', 'value' => ['stringValue' => 'us']],
        ]);
});
