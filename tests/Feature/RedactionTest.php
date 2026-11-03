<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Route;
use Larasell\Chronicle\Exceptions\StructuredInvalidArgumentException;
use Larasell\Chronicle\Exceptions\StructuredLogicException;
use Larasell\Chronicle\Tests\Fixtures\EmailMaskingRedactor;
use Larasell\Chronicle\Tests\Fixtures\NotASampler;
use Larasell\Chronicle\WideEvents\Chronicle;
use Larasell\Chronicle\WideEvents\Requests\Middleware\LogRequests;
use Larasell\Chronicle\WideEvents\Requests\RequestLogger;

/**
 * The route_params fields of an event.
 *
 * @param  array<mixed>  $entry
 * @return array<mixed>
 */
function routeParamFields(array $entry): array
{
    return array_filter($entry, fn (int|string $key): bool => str_starts_with((string) $key, 'route_params.'), ARRAY_FILTER_USE_KEY);
}

/**
 * Builds Chronicle and the request logger again, since both read their config once.
 */
function rebuildChronicle(): void
{
    app()->forgetInstance(Chronicle::class);
    app()->forgetInstance(RequestLogger::class);
}

it('logs the query as fields of its own and redacts the values of secret keys', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::get('/search', fn () => 'ok')->middleware(LogRequests::class);

    // Act
    $this->get('/search?page=2&token=abc&filter[status]=paid&filter[api_key]=k1&X-Amz-Signature=s1');

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['url'])->toBe('http://localhost/search')
        ->and($entry['query.page'])->toBe('2')
        ->and($entry['query.token'])->toBe('[redacted]')
        ->and($entry['query.filter.status'])->toBe('paid')
        ->and($entry['query.filter.api_key'])->toBe('[redacted]')
        ->and($entry['query.X-Amz-Signature'])->toBe('[redacted]')
        ->and($entry)->not->toHaveKey('query_count');
});

it('keeps 32 query values and counts all of them', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::get('/search', fn () => 'ok')->middleware(LogRequests::class);
    $query = http_build_query(array_combine(
        array_map(fn (int $index): string => "q{$index}", range(1, 40)),
        range(1, 40),
    ));

    // Act
    $this->get("/search?{$query}");

    // Assert
    $entry = chronicleEntries($handler)[0];
    $queryFields = array_filter(array_keys($entry), fn (string $key): bool => str_starts_with($key, 'query.'));

    expect($queryFields)->toHaveCount(32)
        ->and($entry['query.q32'])->toBe('32')
        ->and($entry)->not->toHaveKey('query.q33')
        ->and($entry['query_count'])->toBe(40);
});

it('logs the route template as the url and redacts secret route parameters', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::get('/reset-password/{token}', fn () => 'ok')->middleware(LogRequests::class);

    // Act
    $this->get('/reset-password/RESET-TOKEN-SECRET?email=nils@example.com');

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['url'])->toBe('http://localhost/reset-password/{token}')
        ->and($entry['route_params.token'])->toBe('[redacted]')
        ->and($entry['query.email'])->toBe('nils@example.com')
        ->and($handler->getRecords()[0]->message)->not->toContain('RESET-TOKEN-SECRET');
});

it('logs harmless route parameters as they were in the path', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::get('/orders/{order}/lines/{line?}', fn () => 'ok')->middleware(LogRequests::class);
    // A redirect route keeps its destination and status as route defaults, not in its path.
    Route::redirect('/', '/orders/7')->middleware(LogRequests::class);

    // Act
    $this->get('/orders/7/lines/3');
    $this->get('/');

    // Assert
    [$order, $home] = chronicleEntries($handler);

    expect($order['url'])->toBe('http://localhost/orders/{order}/lines/{line?}')
        ->and($order['route_params.order'])->toBe('7')
        ->and($order['route_params.line'])->toBe('3')
        ->and($home['url'])->toBe('http://localhost')
        ->and(routeParamFields($home))->toBe([]);
});

it('keeps the path of a request no route matches', function () {
    // Arrange
    $handler = chronicleTestHandler();

    $kernel = app(Kernel::class);

    if (! $kernel instanceof HttpKernel) {
        throw new UnexpectedValueException('The app has no HTTP kernel to add LogRequests to.');
    }

    $kernel->prependMiddleware(LogRequests::class);

    // Act
    $this->get('/does-not-exist/abc')->assertNotFound();

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['url'])->toBe('http://localhost/does-not-exist/abc')
        ->and($entry['route'])->toBeNull()
        ->and(routeParamFields($entry))->toBe([]);
});

it('logs no query field when the url has no query', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Route::get('/plain', fn () => 'ok')->middleware(LogRequests::class);

    // Act
    $this->get('/plain');

    // Assert
    expect(chronicleEntries($handler)[0])->not->toHaveKey('query');
});

it('redacts a key that contains a secret key as whole words', function (array $fields, string $field) {
    // Arrange
    $handler = chronicleTestHandler();

    // Act
    app(Chronicle::class)->record('checkout', $fields);

    // Assert
    expect(chronicleEntries($handler)[0][$field])->toBe('[redacted]');
})->with([
    'snake case' => [['access_token' => 't1'], 'access_token'],
    'header case' => [['X-Csrf-Token' => 't1'], 'X-Csrf-Token'],
    'camel case' => [['apiToken' => 't1'], 'apiToken'],
    'a longer key' => [['password_confirmation' => 'p1'], 'password_confirmation'],
    'nested' => [['user' => ['password' => 'p1']], 'user.password'],
    'a dotted key' => [['stripe.secret' => 's1'], 'stripe.secret'],
    'a whole group' => [['credentials' => ['user' => 'u1', 'pass' => 'p1']], 'credentials'],
]);

it('keeps keys that only contain a secret key inside a word', function () {
    // Arrange
    $handler = chronicleTestHandler();

    // Act
    app(Chronicle::class)->record('checkout', [
        'tokens' => 3,
        'design' => 'dark',
        'author' => 'nils',
        'session.id' => 's1',
    ]);

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['tokens'])->toBe(3)
        ->and($entry['design'])->toBe('dark')
        ->and($entry['author'])->toBe('nils')
        ->and($entry['session.id'])->toBe('s1');
});

it('redacts secret keys inside lists and objects that are stored as json', function () {
    // Arrange
    $handler = chronicleTestHandler();

    $card = new class implements JsonSerializable
    {
        /** @return array<string, string> */
        public function jsonSerialize(): array
        {
            return ['brand' => 'visa', 'card_number' => '4242424242424242'];
        }
    };

    // Act
    app(Chronicle::class)->record('checkout', [
        'items' => [['sku' => 'a1', 'token' => 't1'], ['sku' => 'b2', 'secret' => ['deep' => 's1']]],
        'cards' => [$card],
    ]);

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['items'])->toBe('[{"sku":"a1","token":"[redacted]"},{"sku":"b2","secret":"[redacted]"}]')
        ->and($entry['cards'])->toBe('[{"brand":"visa","card_number":"[redacted]"}]');
});

it('logs a query exception with the placeholders of its sql instead of the values', function () {
    // Arrange
    $handler = chronicleTestHandler();

    $exception = new QueryException(
        'mysql',
        'insert into users (email, password) values (?, ?)',
        ['jane@example.com', 'hash-of-secret'],
        new PDOException('SQLSTATE[HY000]: General error'),
    );

    // Act
    app(Chronicle::class)->recordError($exception);

    // Assert
    expect(chronicleEntries($handler)[0]['error.message'])
        ->toBe('SQLSTATE[HY000]: General error (Connection: mysql, SQL: insert into users (email, password) values (?, ?))');
});

it('lets the app redactor decide on every value after the secret keys', function () {
    // Arrange
    config()->set('chronicle.redactor', EmailMaskingRedactor::class);
    rebuildChronicle();
    $handler = chronicleTestHandler();

    // Act
    app(Chronicle::class)->record('invite', [
        'user' => ['email' => 'jane@example.com', 'password' => 'jane@secret'],
        'invitees' => ['max@example.com', 'none'],
    ]);

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['user.email'])->toBe('***@example.com')
        ->and($entry['user.password'])->toBe('[redacted]')
        ->and($entry['invitees'])->toBe('["***@example.com","none"]');
});

it('fails at boot when chronicle redact lists something other than a key', function () {
    // Arrange
    config()->set('chronicle.redact', ['password', '']);
    rebuildChronicle();

    // Act & Assert
    app(Chronicle::class);
})->throws(StructuredInvalidArgumentException::class, 'chronicle.redact must only list keys, found string');

it('fails at boot when the configured redactor does not implement the interface', function () {
    // Arrange
    config()->set('chronicle.redactor', NotASampler::class);
    rebuildChronicle();

    // Act & Assert
    app(Chronicle::class);
})->throws(StructuredLogicException::class, 'chronicle.redactor must resolve to an instance of');

it('leaves out the ip when log ip is off', function () {
    // Arrange
    config()->set('chronicle.log_ip', false);
    $handler = chronicleTestHandler();

    Route::get('/plain', fn () => 'ok')->middleware(LogRequests::class);

    // Act
    $this->get('/plain');

    // Assert
    expect(chronicleEntries($handler)[0]['ip'])->toBeNull();
});
