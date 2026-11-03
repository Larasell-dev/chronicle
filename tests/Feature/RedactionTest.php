<?php

declare(strict_types=1);

use Larasell\Chronicle\Exceptions\StructuredInvalidArgumentException;
use Larasell\Chronicle\Exceptions\StructuredLogicException;
use Larasell\Chronicle\Tests\Fixtures\EmailMaskingRedactor;
use Larasell\Chronicle\Tests\Fixtures\NotASampler;
use Larasell\Chronicle\WideEvents\Chronicle;
use Larasell\Chronicle\WideEvents\Requests\RequestLogger;

/**
 * Builds Chronicle and the request logger again, since both read their config once.
 */
function rebuildChronicle(): void
{
    app()->forgetInstance(Chronicle::class);
    app()->forgetInstance(RequestLogger::class);
}

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
