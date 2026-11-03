<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Larasell\Chronicle\Chronicle;
use Larasell\Chronicle\Tests\Fixtures\NotASampler;
use Larasell\Chronicle\Tests\Fixtures\PaymentDeclined;
use Larasell\Chronicle\Tests\Fixtures\RecordingSampler;
use Larasell\Chronicle\Tests\Fixtures\SuccessfulRequestsSampler;
use Larasell\Chronicle\WideEvents\Requests\Exceptions\InvalidSampler;
use Larasell\Chronicle\WideEvents\Requests\Middleware\LogRequests;
use Larasell\Chronicle\WideEvents\Requests\RequestLogger;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use RuntimeException;

function chronicleTestHandler(): TestHandler
{
    config()->set('chronicle.enabled', true);
    config()->set('chronicle.default', 'test');
    $handler = new TestHandler;
    config()->set('chronicle.channels.test', [
        'driver' => 'custom',
        'via' => fn (): Logger => new Logger('test', [$handler]),
    ]);

    return $handler;
}

function chronicleEntries(TestHandler $handler): array
{
    return array_map(
        fn (LogRecord $record) => json_decode($record->message, true),
        $handler->getRecords(),
    );
}

it('writes one structured json entry per request', function () {
    config()->set('chronicle.enabled', true);

    @unlink(storage_path('logs/chronicle.log'));

    Route::post('/login', fn () => 'ok')->middleware(LogRequests::class);

    $this->post('/login', ['email' => 'nils@example.com']);

    $log = file_get_contents(storage_path('logs/chronicle.log'));

    expect($log)->toBeString();

    $entry = json_decode(trim(explode("\n", trim($log))[0]), true);

    expect($entry)->toBeArray()
        ->and($entry['type'])->toBe('request')
        ->and($entry['method'])->toBe('POST')
        ->and($entry['url'])->toBe('http://localhost/login')
        ->and($entry['status'])->toBe(200)
        ->and($entry['duration_ms'])->toBeFloat()
        ->and($entry['ip'])->toBe('127.0.0.1')
        ->and($entry['user.id'])->toBeNull()
        ->and($entry['timestamp'])->toBeString();
});

// Readers of chronicle.log parse it one line per event, so a newline inside a field must stay
// escaped in the JSON rather than end the line.
it('writes an event with line breaks in a field as one line of the log file', function () {
    config()->set('chronicle.enabled', true);

    @unlink(storage_path('logs/chronicle.log'));

    app(Chronicle::class)->record('import.failed', ['output' => "line one\nline two\r\nline three"]);

    $lines = explode("\n", rtrim(file_get_contents(storage_path('logs/chronicle.log')), "\n"));

    expect($lines)->toHaveCount(1)
        ->and(json_decode($lines[0], true)['output'])->toBe("line one\nline two\r\nline three");
});

it('logs the uri of routes without a name, also in a group with a name prefix', function () {
    $handler = chronicleTestHandler();

    Route::name('shop.')->group(function (): void {
        Route::get('/products/{product}', fn () => 'ok')->middleware(LogRequests::class);
        Route::get('/cart', fn () => 'ok')->middleware(LogRequests::class)->name('cart');
    });

    $this->get('/products/1');
    $this->get('/cart');

    expect(array_column(chronicleEntries($handler), 'route'))->toBe(['products/{product}', 'shop.cart']);
});

it('does not log when disabled', function () {
    config()->set('chronicle.enabled', false);

    @unlink(storage_path('logs/chronicle.log'));

    Route::get('/ping', fn () => 'ok')->middleware(LogRequests::class);

    $this->get('/ping')->assertHeaderMissing('X-Request-Id');

    expect(file_exists(storage_path('logs/chronicle.log')))->toBeFalse();
});

// Each status on both sides of a boundary between levels, so an off-by-one in a comparison fails.
it('logs a request at the level its status falls into', function (int $status, Level $level) {
    $handler = chronicleTestHandler();

    Route::get('/status', fn () => response('', $status))->middleware(LogRequests::class);

    $this->get('/status');

    expect($handler->getRecords())->toHaveCount(1)
        ->and($handler->getRecords()[0]->level)->toBe($level)
        ->and(chronicleEntries($handler)[0]['status'])->toBe($status);
})->with([
    '200' => [200, Level::Info],
    '302' => [302, Level::Info],
    '399, the last info status' => [399, Level::Info],
    '400, the first warning status' => [400, Level::Warning],
    '499, the last warning status' => [499, Level::Warning],
    '500, the first error status' => [500, Level::Error],
    '599' => [599, Level::Error],
]);

it('logs 5xx responses at error level', function () {
    $handler = chronicleTestHandler();

    Route::get('/boom', fn () => throw new RuntimeException('boom'))->middleware(LogRequests::class);

    $this->get('/boom');

    $records = array_values(array_filter(
        $handler->getRecords(),
        fn (LogRecord $record) => (json_decode($record->message, true)['type'] ?? null) === 'request',
    ));

    expect($records)->toHaveCount(1)
        ->and($records[0]->level)->toBe(Level::Error);
});

it('logs a failed request as a single event with the exception merged', function () {
    $handler = chronicleTestHandler();

    Route::get('/boom', fn () => throw new RuntimeException('boom'))->middleware(LogRequests::class);

    $this->get('/boom')->assertStatus(500);

    expect($handler->getRecords())->toHaveCount(1);

    $entry = chronicleEntries($handler)[0];

    expect($entry['type'])->toBe('request')
        ->and($entry['status'])->toBe(500)
        ->and($entry['error.code'])->toBeNull()
        ->and($entry['error.message'])->toBe('boom');
});

it('merges the exception from a json error response', function () {
    $handler = chronicleTestHandler();

    $response = response()->json(['component' => 'error-page'], 500)
        ->withException(new RuntimeException('boom'));

    app(RequestLogger::class)->log(Request::create('/contact', 'POST'), $response, microtime(true));

    $entry = chronicleEntries($handler)[0];

    expect($entry['status'])->toBe(500)
        ->and($entry['error.message'])->toBe('boom');
});

it('logs structured exception fields on failed requests', function () {
    $handler = chronicleTestHandler();

    Route::get('/boom', fn () => throw PaymentDeclined::byIssuer())->middleware(LogRequests::class);

    $this->get('/boom')->assertStatus(500);

    $entry = chronicleEntries($handler)[0];

    expect($entry['error.code'])->toBe('billing.declined')
        ->and($entry['error.why'])->toBe('Issuer declined the charge')
        ->and($entry['error.fix'])->toBe('Try a different card');
});

it('logs http exceptions on failed requests', function () {
    $handler = chronicleTestHandler();

    Route::get('/missing', fn () => abort(404))->middleware(LogRequests::class);

    $this->get('/missing')->assertStatus(404);

    $entry = chronicleEntries($handler)[0];

    expect($entry['status'])->toBe(404)
        ->and($entry['error.message'])->toBe('');
});

it('merges context accumulated through chronicle set into the request event', function () {
    $handler = chronicleTestHandler();

    Route::get('/context', function () {
        Context::add([
            'user.email' => 'nils@example.com',
            'cart.items' => 3,
        ]);

        return 'ok';
    })->middleware(LogRequests::class);

    $this->get('/context');

    $entry = chronicleEntries($handler)[0];

    expect($entry['user.email'])->toBe('nils@example.com')
        ->and($entry['cart.items'])->toBe(3);
});

it('lets accumulated context override request fields but not error fields', function () {
    $handler = chronicleTestHandler();

    Route::get('/override', function () {
        Context::add([
            'user.id' => 999,
            'error.code' => 'hijacked',
        ]);

        throw new RuntimeException('boom');
    })->middleware(LogRequests::class);

    $this->get('/override')->assertStatus(500);

    $entry = chronicleEntries($handler)[0];

    expect($entry['user.id'])->toBe(999)
        ->and($entry['error.code'])->toBeNull()
        ->and($entry['error.message'])->toBe('boom');
});

it('collapses nested context arrays into dotted keys and json strings', function () {
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

    $this->get('/nested');

    $entry = chronicleEntries($handler)[0];

    expect($entry['user.email'])->toBe('nils@example.com')
        ->and($entry['user.plan'])->toBe('pro')
        ->and($entry['user.licenses'])->toBe('["pro","shop"]');
});

it('keeps request user data when context enriches the same group', function () {
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

    $entry = chronicleEntries($handler)[0];

    expect($entry['user.id'])->toBe(42)
        ->and($entry['user.email'])->toBe('nils@example.com')
        ->and($entry['user.email_verified'])->toBeTrue();
});

it('does not leak accumulated context once the context is flushed', function () {
    $handler = chronicleTestHandler();

    Route::get('/marker', function () {
        Context::add(['request.marker' => 'first']);

        return 'ok';
    })->middleware(LogRequests::class);

    Route::get('/plain', fn () => 'ok')->middleware(LogRequests::class);

    $this->get('/marker');

    Context::flush();

    $this->get('/plain');

    $entries = chronicleEntries($handler);

    expect($entries[0]['request.marker'])->toBe('first')
        ->and($entries[1])->not->toHaveKey('request.marker');
});

it('accepts an explicit level in chronicle record', function () {
    $handler = chronicleTestHandler();

    app(Chronicle::class)->record('request', ['status' => 200], Level::Warning);

    expect($handler->getRecords())->toHaveCount(1)
        ->and($handler->getRecords()[0]->level)->toBe(Level::Warning)
        ->and(chronicleEntries($handler)[0]['status'])->toBe(200);
});

it('flattens fields in chronicle record and merges context under them', function () {
    $handler = chronicleTestHandler();

    Context::add(['job' => ['id' => 7], 'order.id' => 1]);

    app(Chronicle::class)->record('order.paid', ['order' => ['id' => 2, 'total' => 10]]);

    $entry = chronicleEntries($handler)[0];

    expect($entry['order.id'])->toBe(2)
        ->and($entry['order.total'])->toBe(10)
        ->and($entry['job.id'])->toBe(7)
        ->and($entry)->not->toHaveKey('order');
});

it('accepts a level name as a string in chronicle record', function () {
    $handler = chronicleTestHandler();

    app(Chronicle::class)->record('order.paid', [], 'warning');

    expect($handler->getRecords()[0]->level)->toBe(Level::Warning);
});

// A typo in a level is a programmer error, so it must fail loudly rather than log at a guess.
it('refuses an unknown level name and writes nothing', function () {
    $handler = chronicleTestHandler();

    expect(fn () => app(Chronicle::class)->record('order.paid', [], 'loud'))->toThrow(Error::class)
        ->and($handler->getRecords())->toHaveCount(0);
});

it('flattens fields nested several levels deep', function () {
    $handler = chronicleTestHandler();

    app(Chronicle::class)->record('order.paid', [
        'order' => ['customer' => ['address' => ['country' => 'DE', 'lines' => ['a', 'b']]]],
    ]);

    $entry = chronicleEntries($handler)[0];

    expect($entry['order.customer.address.country'])->toBe('DE')
        ->and($entry['order.customer.address.lines'])->toBe('["a","b"]')
        ->and($entry)->not->toHaveKeys(['order', 'order.customer', 'order.customer.address']);
});

// The JSON of a list is cut at 1000 bytes; one byte below, at and above the limit.
it('cuts the json of a list at 1000 bytes', function (int $length, int $expected) {
    $handler = chronicleTestHandler();

    // ["…"] adds four bytes around the string.
    app(Chronicle::class)->record('order.paid', ['tags' => [str_repeat('a', $length - 4)]]);

    expect(strlen(chronicleEntries($handler)[0]['tags']))->toBe($expected);
})->with([
    '999 bytes' => [999, 999],
    '1000 bytes' => [1000, 1000],
    '1001 bytes' => [1001, 1000],
]);

it('drops request logs when the sampler returns false', function () {
    $handler = chronicleTestHandler();

    config()->set('chronicle.sampling', SuccessfulRequestsSampler::class);

    Route::get('/ok', fn () => 'ok')->middleware(LogRequests::class);
    Route::get('/missing', fn () => abort(404))->middleware(LogRequests::class);

    $this->get('/ok');
    $this->get('/missing');

    expect($handler->getRecords())->toHaveCount(1)
        ->and(chronicleEntries($handler)[0]['status'])->toBe(200);
});

it('keeps every request log when no sampler is configured', function () {
    $handler = chronicleTestHandler();

    Route::get('/ping', fn () => 'ok')->middleware(LogRequests::class);

    $this->get('/ping');

    expect($handler->getRecords())->toHaveCount(1);
});

it('passes request, response and payload to the sampler', function () {
    $handler = chronicleTestHandler();

    config()->set('chronicle.sampling', RecordingSampler::class);

    Route::get('/ping', fn () => 'ok')->middleware(LogRequests::class);

    $this->get('/ping');

    expect($handler->getRecords())->toHaveCount(1)
        ->and(RecordingSampler::$seen)->toBe(['GET', 200, 'http://localhost/ping']);
});

it('throws when the configured sampler does not implement the interface', function () {
    config()->set('chronicle.sampling', NotASampler::class);

    app(RequestLogger::class)->log(request(), response('ok'), microtime(true));
})->throws(InvalidSampler::class, 'chronicle.sampling must resolve to an instance of Larasell\Chronicle\WideEvents\Requests\Sampler');
