<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Larasell\Chronicle\Exceptions\StructuredInvalidArgumentException;
use Larasell\Chronicle\Exceptions\StructuredLogicException;
use Larasell\Chronicle\Tests\Fixtures\EveryTenthRequestSampler;
use Larasell\Chronicle\Tests\Fixtures\NotASampler;
use Larasell\Chronicle\Tests\Fixtures\PaymentDeclined;
use Larasell\Chronicle\Tests\Fixtures\RecordingSampler;
use Larasell\Chronicle\Tests\Fixtures\SuccessfulRequestsSampler;
use Larasell\Chronicle\WideEvents\Chronicle;
use Larasell\Chronicle\WideEvents\Enums\Level;
use Larasell\Chronicle\WideEvents\Requests\Exceptions\InvalidSampler;
use Larasell\Chronicle\WideEvents\Requests\Middleware\LogRequests;
use Larasell\Chronicle\WideEvents\Requests\RequestLogger;
use Larasell\Chronicle\WideEvents\Requests\Sampler;
use Monolog\Level as MonologLevel;
use Monolog\LogRecord;
use RuntimeException;

it('writes one structured json entry per request', function () {
    // Arrange
    config()->set('chronicle.enabled', true);

    Route::post('/login', fn () => 'ok')->middleware(LogRequests::class);

    // Act
    $this->post('/login', ['email' => 'nils@example.com']);

    // Assert
    $entry = chronicleEntry(explode("\n", trim(File::get(chronicleLogPath())))[0]);

    expect($entry['type'])->toBe('request')
        ->and($entry['method'])->toBe('POST')
        ->and($entry['url'])->toBe('http://localhost/login')
        ->and($entry['status'])->toBe(200)
        ->and($entry['duration_ms'])->toBeFloat()
        ->and($entry['ip'])->toBe('127.0.0.1')
        ->and($entry['user.id'])->toBeNull()
        ->and($entry['timestamp'])->toBeString();
});

it('logs the uri of routes without a name, also in a group with a name prefix', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::name('shop.')->group(function (): void {
        Route::get('/products/{product}', fn () => 'ok')->middleware(LogRequests::class);
        Route::get('/cart', fn () => 'ok')->middleware(LogRequests::class)->name('cart');
    });

    // Act
    $this->get('/products/1');
    $this->get('/cart');

    // Assert
    expect(array_column(chronicleEntries($handler), 'route'))->toBe(['products/{product}', 'shop.cart']);
});

it('does not log when disabled', function () {
    // Arrange
    config()->set('chronicle.enabled', false);

    Route::get('/ping', fn () => 'ok')->middleware(LogRequests::class);

    // Act
    $this->get('/ping')->assertHeaderMissing('X-Request-Id');

    // Assert
    expect(file_exists(chronicleLogPath()))->toBeFalse();
});

it('logs 2xx responses at info level', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::get('/ok', fn () => 'ok')->middleware(LogRequests::class);

    // Act
    $this->get('/ok');

    // Assert
    expect($handler->getRecords())->toHaveCount(1)
        ->and($handler->getRecords()[0]->level)->toBe(MonologLevel::Info);
});

it('logs 3xx responses at info level', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::get('/redirect', fn () => redirect('/ok'))->middleware(LogRequests::class);

    // Act
    $this->get('/redirect');

    // Assert
    expect($handler->getRecords())->toHaveCount(1)
        ->and($handler->getRecords()[0]->level)->toBe(MonologLevel::Info);
});

it('logs 4xx responses at warning level', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::get('/not-found', fn () => abort(404))->middleware(LogRequests::class);

    // Act
    $this->get('/not-found');

    // Assert
    expect($handler->getRecords())->toHaveCount(1)
        ->and($handler->getRecords()[0]->level)->toBe(MonologLevel::Warning);
});

it('logs 5xx responses at error level', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::get('/boom', fn () => throw new RuntimeException('boom'))->middleware(LogRequests::class);

    // Act
    $this->get('/boom');

    // Assert
    $records = array_values(array_filter(
        $handler->getRecords(),
        fn (LogRecord $record) => (chronicleEntry($record->message)['type'] ?? null) === 'request',
    ));

    expect($records)->toHaveCount(1)
        ->and($records[0]->level)->toBe(MonologLevel::Error);
});

it('logs a failed request as a single event with the exception merged', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::get('/boom', fn () => throw new RuntimeException('boom'))->middleware(LogRequests::class);

    // Act
    $this->get('/boom')->assertStatus(500);

    // Assert
    expect($handler->getRecords())->toHaveCount(1);

    $entry = chronicleEntries($handler)[0];

    expect($entry['type'])->toBe('request')
        ->and($entry['status'])->toBe(500)
        ->and($entry['error.code'])->toBeNull()
        ->and($entry['error.message'])->toBe('boom');
});

it('measures the duration in milliseconds from an hrtime start', function () {
    // Arrange
    $handler = chronicleTestHandler();

    // Act
    // Started 25 ms ago on the monotonic clock.
    app(RequestLogger::class)->log(Request::create('/cart'), response('ok'), hrtime(true) - 25_000_000);

    // Assert
    expect(chronicleEntries($handler)[0]['duration_ms'])->toBeGreaterThanOrEqual(25.0)->toBeLessThan(1000.0);
});

it('throws instead of logging a negative duration', function () {
    // Act & Assert
    app(RequestLogger::class)->log(Request::create('/cart'), response('ok'), hrtime(true) + 5_000_000_000);
})->throws(StructuredLogicException::class, 'in the future');

it('merges the exception from a json error response', function () {
    // Arrange
    $handler = chronicleTestHandler();

    $response = response()->json(['component' => 'error-page'], 500)
        ->withException(new RuntimeException('boom'));

    // Act
    app(RequestLogger::class)->log(Request::create('/contact', 'POST'), $response, hrtime(true));

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['status'])->toBe(500)
        ->and($entry['error.message'])->toBe('boom');
});

it('logs structured exception fields on failed requests', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::get('/boom', fn () => throw PaymentDeclined::byIssuer())->middleware(LogRequests::class);

    // Act
    $this->get('/boom')->assertStatus(500);

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['error.code'])->toBe('billing.declined')
        ->and($entry['error.why'])->toBe('Issuer declined the charge')
        ->and($entry['error.fix'])->toBe('Try a different card');
});

it('logs http exceptions on failed requests', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::get('/missing', fn () => abort(404))->middleware(LogRequests::class);

    // Act
    $this->get('/missing')->assertStatus(404);

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['status'])->toBe(404)
        ->and($entry['error.message'])->toBe('');
});

it('merges context accumulated through chronicle set into the request event', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::get('/context', function () {
        Context::add([
            'user.email' => 'nils@example.com',
            'cart.items' => 3,
        ]);

        return 'ok';
    })->middleware(LogRequests::class);

    // Act
    $this->get('/context');

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['user.email'])->toBe('nils@example.com')
        ->and($entry['cart.items'])->toBe(3);
});

it('lets accumulated context override request fields but not error fields', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::get('/override', function () {
        Context::add([
            'user.id' => 999,
            'error.code' => 'hijacked',
        ]);

        throw new RuntimeException('boom');
    })->middleware(LogRequests::class);

    // Act
    $this->get('/override')->assertStatus(500);

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['user.id'])->toBe(999)
        ->and($entry['error.code'])->toBeNull()
        ->and($entry['error.message'])->toBe('boom');
});

it('collapses nested context arrays into dotted keys and json strings', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::get('/nested', function () {
        Context::add([
            'user' => [
                'email' => 'nils@example.com',
                'plan' => 'pro',
                'licenses' => ['pro', 'shop'],
            ],
        ]);

        return 'ok';
    })->middleware(LogRequests::class);

    // Act
    $this->get('/nested');

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['user.email'])->toBe('nils@example.com')
        ->and($entry['user.plan'])->toBe('pro')
        ->and($entry['user.licenses'])->toBe('["pro","shop"]');
});

it('keeps request user data when context enriches the same group', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::get('/enriched', function () {
        Context::add([
            'user' => [
                'email' => 'nils@example.com',
                'email_verified' => true,
            ],
        ]);

        return 'ok';
    })->middleware(LogRequests::class);

    // Act
    $this->actingAs(new class implements Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return 42;
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    })->get('/enriched');

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['user.id'])->toBe(42)
        ->and($entry['user.email'])->toBe('nils@example.com')
        ->and($entry['user.email_verified'])->toBeTrue();
});

it('does not leak accumulated context once the context is flushed', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::get('/marker', function () {
        Context::add(['request.marker' => 'first']);

        return 'ok';
    })->middleware(LogRequests::class);

    Route::get('/plain', fn () => 'ok')->middleware(LogRequests::class);

    // Act
    $this->get('/marker');

    Context::flush();

    $this->get('/plain');

    // Assert
    $entries = chronicleEntries($handler);

    expect($entries[0]['request.marker'])->toBe('first')
        ->and($entries[1])->not->toHaveKey('request.marker');
});

it('accepts an explicit level in chronicle record', function () {
    // Arrange
    $handler = chronicleTestHandler();

    // Act
    app(Chronicle::class)->record('request', ['status' => 200], Level::Warning);

    // Assert
    expect($handler->getRecords())->toHaveCount(1)
        ->and($handler->getRecords()[0]->level)->toBe(MonologLevel::Warning)
        ->and(chronicleEntries($handler)[0]['status'])->toBe(200);
});

it('accepts a level by its name, in any case', function (string $name, MonologLevel $expected) {
    // Arrange
    $handler = chronicleTestHandler();

    // Act
    app(Chronicle::class)->record('request', level: $name);

    // Assert
    expect($handler->getRecords()[0]->level)->toBe($expected);
})->with([
    'warning' => ['warning', MonologLevel::Warning],
    'Critical' => ['Critical', MonologLevel::Critical],
    'DEBUG' => ['DEBUG', MonologLevel::Debug],
]);

it('throws a structured exception for an unknown level name', function () {
    // Act & Assert
    app(Chronicle::class)->record('request', level: 'warn');
})->throws(StructuredInvalidArgumentException::class, 'There is no log level named [warn]');

it('maps every chronicle level to the monolog level of the same name', function () {
    // Act & Assert
    foreach (Level::cases() as $level) {
        expect($level->toMonolog()->toPsrLogLevel())->toBe($level->value);
    }
});

it('flattens fields in chronicle record and merges context under them', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Context::add(['job' => ['id' => 7], 'order.id' => 1]);

    // Act
    app(Chronicle::class)->record('order.paid', ['order' => ['id' => 2, 'total' => 10]]);

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['order.id'])->toBe(2)
        ->and($entry['order.total'])->toBe(10)
        ->and($entry['job.id'])->toBe(7)
        ->and($entry)->not->toHaveKey('order');
});

it('truncates long string fields', function () {
    // Arrange
    $handler = chronicleTestHandler();

    // Act
    app(Chronicle::class)->record('big', ['body' => str_repeat('x', 5000)]);

    // Assert
    expect(chronicleEntries($handler)[0]['body'])->toBe(str_repeat('x', 1000));
});

it('stores arrays nested deeper than 32 levels as one json value, however deep', function () {
    // Arrange
    $handler = chronicleTestHandler();

    // 20,000 levels used to exhaust 128 MB of memory in the recursive flatten.
    $fields = 'leaf';
    for ($level = 0; $level < 20_000; $level++) {
        $fields = ['a' => $fields];
    }

    // Act
    app(Chronicle::class)->record('deep', $fields);

    // Assert
    // Levels 1 to 32 become the key; the rest is too deep to encode, so it becomes null.
    expect(chronicleEntries($handler)[0])->toMatchArray([
        implode('.', array_fill(0, 32, 'a')) => '{"a":null}',
    ]);
});

it('keeps truncated lists valid json and records their full count', function () {
    // Arrange
    $handler = chronicleTestHandler();
    $tags = array_map(fn (int $index): string => "tag-{$index}", range(0, 199));

    // Act
    app(Chronicle::class)->record('tags', ['user' => ['tags' => $tags]]);

    // Assert
    $entry = chronicleEntries($handler)[0];
    $json = Arr::string($entry, 'user.tags');
    $kept = chronicleEntry($json);

    // The kept tags are the longest prefix of the list whose JSON fits in 1,000 bytes.
    expect($kept)->toBe(array_slice($tags, 0, count($kept)))
        ->and(strlen($json))->toBeLessThanOrEqual(1000)
        ->and(strlen(json_encode(array_slice($tags, 0, count($kept) + 1), JSON_THROW_ON_ERROR)))->toBeGreaterThan(1000)
        ->and($entry['user.tags_count'])->toBe(200);
});

it('bounds objects like the strings and arrays they encode to', function () {
    // Arrange
    $handler = chronicleTestHandler();

    // Act
    app(Chronicle::class)->record('import', [
        'serializable' => new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return str_repeat('b', 5000);
            }
        },
        'arrayable' => new class implements Arrayable
        {
            /**
             * @return array<string, string>
             */
            public function toArray(): array
            {
                return ['sku' => str_repeat('e', 5000)];
            }
        },
        'collection' => collect(range(1, 2000)),
        'plain' => (object) ['deep' => ['deeper' => str_repeat('c', 5000)]],
        'dto' => new class
        {
            public string $note;

            public function __construct()
            {
                $this->note = str_repeat('d', 5000);
            }
        },
    ]);

    // Assert
    $entry = chronicleEntries($handler)[0];

    // "[1,…,276]" is 4 × 276 - 107 = 997 bytes: 9 one-digit, 90 two-digit and 177 three-digit
    // numbers, 275 commas and 2 brackets. The 277th element would make it 1,001.
    expect($entry)->toMatchArray([
        'serializable' => str_repeat('b', 1000),
        'arrayable.sku' => str_repeat('e', 1000),
        'collection' => json_encode(range(1, 276), JSON_THROW_ON_ERROR),
        'collection_count' => 2000,
        'plain.deep.deeper' => str_repeat('c', 1000),
        'dto.note' => str_repeat('d', 1000),
    ]);
});

it('does not add a count to lists that fit', function () {
    // Arrange
    $handler = chronicleTestHandler();

    // Act
    app(Chronicle::class)->record('tags', ['tags' => ['a', 'b']]);

    // Assert
    expect(chronicleEntries($handler)[0])->not->toHaveKey('tags_count');
});

it('drops context fields first once an event has more than 256 fields', function () {
    // Arrange
    $handler = chronicleTestHandler();

    foreach (range(0, 999) as $index) {
        Context::add("context.f{$index}", $index);
    }

    // Act
    app(Chronicle::class)->record('wide', ['order' => ['id' => 7]]);

    // Assert
    $entry = chronicleEntries($handler)[0];

    // 2 header + 1,000 context + 1 field = 1,003. Keeping 256 with the count means dropping
    // 1,003 - 256 + 1 = 748 fields, all of them the latest context fields.
    expect($entry)->toHaveCount(256)
        ->and($entry['chronicle.fields_dropped'])->toBe(748)
        ->and($entry['type'])->toBe('wide')
        ->and($entry['order.id'])->toBe(7)
        ->and($entry['context.f251'])->toBe(251)
        ->and($entry)->not->toHaveKey('context.f252');
});

function unencodableValue(string $kind): mixed
{
    return match ($kind) {
        'NAN' => fdiv(0, 0),
        'INF' => fdiv(1, 0),
        '-INF' => log(0),
        'a resource' => fopen('php://memory', 'r'),
        default => throw new UnexpectedValueException("Unknown kind [{$kind}]."),
    };
}

it('writes the event and reports a value json can not encode', function (string $kind, string $reason, string $source) {
    // Arrange
    $handler = chronicleTestHandler();
    $fields = ['order.id' => 7];

    if ($source === 'context') {
        Context::add('bad', unencodableValue($kind));
    } else {
        $fields['bad'] = unencodableValue($kind);
    }

    // Act
    app(Chronicle::class)->record('checkout', $fields);

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['type'])->toBe('checkout')
        ->and($entry['order.id'])->toBe(7)
        ->and($entry)->toHaveKey('bad')
        ->and($entry['bad'])->toBeNull()
        ->and(chronicleEntry(Arr::string($entry, 'chronicle.unencodable')))->toBe(['bad' => $reason]);
})->with([
    'NAN' => ['NAN', 'NAN'],
    'INF' => ['INF', 'INF'],
    '-INF' => ['-INF', '-INF'],
    'a resource' => ['a resource', 'resource (stream): Type is not supported'],
])->with(['fields', 'context']);

it('reports every value json can not encode in one object', function () {
    // Arrange
    $handler = chronicleTestHandler();

    // Act
    app(Chronicle::class)->record('checkout', [
        'conversion' => unencodableValue('NAN'),
        'score' => unencodableValue('-INF'),
        'order.id' => 7,
        'file' => unencodableValue('a resource'),
    ]);

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['order.id'])->toBe(7)
        ->and(chronicleEntry(Arr::string($entry, 'chronicle.unencodable')))->toBe([
            'conversion' => 'NAN',
            'score' => '-INF',
            'file' => 'resource (stream): Type is not supported',
        ]);
});

it('keeps the encodable elements of a list and reports the others', function () {
    // Arrange
    $handler = chronicleTestHandler();

    // Act
    app(Chronicle::class)->record('ratios', ['ratios' => [1, unencodableValue('NAN'), 2, unencodableValue('INF')]]);

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['ratios'])->toBe('[1,null,2,null]')
        ->and(chronicleEntry(Arr::string($entry, 'chronicle.unencodable')))->toBe(['ratios' => '[1] NAN, and 1 more']);
});

it('does not report a bad context value that a field replaces', function () {
    // Arrange
    $handler = chronicleTestHandler();
    Context::add('score', unencodableValue('NAN'));

    // Act
    app(Chronicle::class)->record('search', ['score' => 3]);

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['score'])->toBe(3)
        ->and($entry)->not->toHaveKey('chronicle.unencodable');
});

it('reports an object whose jsonSerialize throws', function () {
    // Arrange
    $handler = chronicleTestHandler();

    // Act
    app(Chronicle::class)->record('checkout', ['order.id' => 7, 'money' => new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            throw new RuntimeException('currency missing');
        }
    }]);

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['order.id'])->toBe(7)
        ->and($entry['money'])->toBeNull()
        ->and(chronicleEntry(Arr::string($entry, 'chronicle.unencodable')))->toBe([
            'money' => 'JsonSerializable@anonymous: jsonSerialize() threw RuntimeException',
        ]);
});

it('counts the unencodable report towards the field limit', function () {
    // Arrange
    $handler = chronicleTestHandler();

    foreach (range(0, 999) as $index) {
        Context::add("context.f{$index}", $index);
    }

    // Act
    app(Chronicle::class)->record('wide', ['score' => unencodableValue('NAN')]);

    // Assert
    $entry = chronicleEntries($handler)[0];

    // 2 header + 1,000 context + 1 field = 1,003. Keeping 256 with the dropped count and the
    // report means dropping 1,003 - 256 + 2 = 749 fields.
    expect($entry)->toHaveCount(256)
        ->and($entry['chronicle.fields_dropped'])->toBe(749)
        ->and(chronicleEntry(Arr::string($entry, 'chronicle.unencodable')))->toBe(['score' => 'NAN']);
});

it('cuts the unencodable report to 1000 bytes of valid json and counts every value', function () {
    // Arrange
    $handler = chronicleTestHandler();
    $fields = [];

    foreach (range(0, 99) as $index) {
        $fields["f{$index}"] = unencodableValue('NAN');
    }

    // Act
    app(Chronicle::class)->record('wide', $fields);

    // Assert
    $entry = chronicleEntries($handler)[0];
    $json = Arr::string($entry, 'chronicle.unencodable');
    $reported = chronicleEntry($json);
    $expected = array_map(fn (int $index): string => "f{$index}", range(0, count($reported) - 1));

    // Each entry like "f10":"NAN" is about 12 bytes, so 100 of them can't fit in 1,000.
    expect($reported)->toBe(array_fill_keys($expected, 'NAN'))
        ->and(count($reported))->toBeLessThan(100)
        ->and(strlen($json))->toBeLessThanOrEqual(1000)
        ->and($entry['chronicle.unencodable_count'])->toBe(100);
});

/**
 * Configures a sampler and rebuilds the RequestLogger, as the app does once at boot. Returns
 * the sampler the logger now holds, so a test can look at it.
 *
 * @template TSampler of Sampler
 *
 * @param  class-string<TSampler>  $sampler
 * @return TSampler
 */
function useSampler(string $sampler): Sampler
{
    config()->set('chronicle.sampler', $sampler);
    app()->singleton($sampler);
    app()->forgetInstance(RequestLogger::class);
    app(RequestLogger::class);

    return app($sampler);
}

it('registers chronicle channels at boot, pointing stacks at chronicle channels', function () {
    // Act & Assert
    expect(config('logging.channels.chronicle-stack.channels'))->toBe(['chronicle-file', 'chronicle-posthog']);
});

it('drops request logs when the sampler returns false', function () {
    // Arrange
    $handler = chronicleTestHandler();

    useSampler(SuccessfulRequestsSampler::class);

    Route::get('/ok', fn () => 'ok')->middleware(LogRequests::class);
    Route::get('/missing', fn () => abort(404))->middleware(LogRequests::class);

    // Act
    $this->get('/ok');
    $this->get('/missing');

    // Assert
    expect($handler->getRecords())->toHaveCount(1)
        ->and(chronicleEntries($handler)[0]['status'])->toBe(200);
});

it('keeps every request log when no sampler is configured', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::get('/ping', fn () => 'ok')->middleware(LogRequests::class);

    // Act
    $this->get('/ping');

    // Assert
    expect($handler->getRecords())->toHaveCount(1);
});

it('passes request, response and event to the sampler', function () {
    // Arrange
    $handler = chronicleTestHandler();

    $sampler = useSampler(RecordingSampler::class);

    Route::get('/ping', fn () => 'ok')->middleware(LogRequests::class);

    // Act
    $this->get('/ping');

    // Assert
    expect($handler->getRecords())->toHaveCount(1)
        ->and($sampler->seen)->toBe([['GET', 200, 'http://localhost/ping']]);
});

it('fails at boot when the config still sets the old sampling key', function () {
    // Arrange
    config()->set('chronicle.sampling', SuccessfulRequestsSampler::class);
    app()->forgetInstance(RequestLogger::class);

    // Act & Assert
    app(RequestLogger::class);
})->throws(StructuredLogicException::class, 'chronicle.sampling is now chronicle.sampler');

it('builds the sampler once, so it can keep state across requests', function () {
    // Arrange
    $handler = chronicleTestHandler();
    useSampler(EveryTenthRequestSampler::class);

    Route::get('/ping', fn () => 'ok')->middleware(LogRequests::class);

    // Act
    foreach (range(1, 20) as $request) {
        $this->get('/ping');
    }

    // Assert
    expect($handler->getRecords())->toHaveCount(2);
});

it('throws when the configured sampler does not implement the interface', function () {
    // Arrange
    config()->set('chronicle.sampler', NotASampler::class);
    app()->forgetInstance(RequestLogger::class);

    // Act & Assert
    app(RequestLogger::class);
})->throws(InvalidSampler::class, 'chronicle.sampler must resolve to an instance of Larasell\Chronicle\WideEvents\Requests\Sampler');
