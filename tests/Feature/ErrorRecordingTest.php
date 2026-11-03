<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Larasell\Chronicle\Exceptions\HasStructuredFields;
use Larasell\Chronicle\Exceptions\StructuredException;
use Larasell\Chronicle\Exceptions\StructuredUnexpectedValueException;
use Larasell\Chronicle\Tests\Fixtures\CouldNotInviteMember;
use Larasell\Chronicle\Tests\Fixtures\PaymentDeclined;
use Larasell\Chronicle\WideEvents\Chronicle;
use Larasell\Chronicle\WideEvents\Enums\Level;
use Monolog\Level as MonologLevel;
use RuntimeException;

it('records structured fields for exceptions implementing the contract', function () {
    // Arrange
    $handler = chronicleTestHandler();

    $previous = new RuntimeException('network down');
    $exception = PaymentDeclined::byIssuer($previous);

    // Act
    app(Chronicle::class)->recordError($exception);

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($exception->getPrevious())->toBe($previous)
        ->and($exception->errorCode())->toBe('billing.declined')
        ->and($entry['type'])->toBe('error')
        ->and($entry['error.code'])->toBe('billing.declined')
        ->and($entry['error.message'])->toBe('Payment failed')
        ->and($entry['error.why'])->toBe('Issuer declined the charge')
        ->and($entry['error.fix'])->toBe('Try a different card')
        ->and($entry['error.link'])->toBe('https://docs.example.com/errors#billing-declined');
});

it('records domain exceptions built through the structured error constructor', function () {
    // Arrange
    $handler = chronicleTestHandler();

    // Act
    app(Chronicle::class)->recordError(CouldNotInviteMember::emailAlreadyInvited('nils@example.com', 'acme'));

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['error.code'])->toBe('teams.could-not-invite-member.email-already-invited')
        ->and($entry['error.message'])->toBe('Could not invite nils@example.com to team acme: they already have a pending invitation.')
        ->and($entry['error.why'])->toBe('A pending invitation for nils@example.com already exists in team acme')
        ->and($entry['error.fix'])->toBe('Resend the existing invitation or remove it first');
});

it('records structured exceptions thrown inline', function (string $class, string $base) {
    // Arrange
    $handler = chronicleTestHandler();
    $previous = new RuntimeException('network down');

    if (! is_a($class, Throwable::class, true)) {
        throw new UnexpectedValueException("The dataset must name a throwable class, [{$class}] given.");
    }

    if (! class_exists($base)) {
        throw new UnexpectedValueException("The dataset must name an SPL base class, [{$base}] given.");
    }

    // Act
    try {
        throw new $class(
            errorCode: 'billing.declined',
            message: 'Payment failed',
            why: 'Issuer declined the charge',
            fix: 'Try a different card',
            link: 'https://docs.example.com/errors#billing-declined',
            previous: $previous,
        );
    } catch (StructuredException $exception) {
        app(Chronicle::class)->recordError($exception);
    }

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($exception)->toBeInstanceOf($base)
        ->and($exception->getPrevious())->toBe($previous)
        ->and($entry['error.code'])->toBe('billing.declined')
        ->and($entry['error.message'])->toBe('Payment failed')
        ->and($entry['error.why'])->toBe('Issuer declined the charge')
        ->and($entry['error.fix'])->toBe('Try a different card')
        ->and($entry['error.link'])->toBe('https://docs.example.com/errors#billing-declined');
})->with(array_map(
    fn (string $base) => ['Larasell\\Chronicle\\Exceptions\\Structured'.$base, $base],
    [
        'LogicException', 'BadFunctionCallException', 'BadMethodCallException', 'DomainException',
        'InvalidArgumentException', 'LengthException', 'OutOfRangeException',
        'RuntimeException', 'OutOfBoundsException', 'OverflowException', 'RangeException',
        'UnderflowException', 'UnexpectedValueException',
    ],
));

it('degrades to a stable shape for plain throwables', function () {
    // Arrange
    $handler = chronicleTestHandler();

    // Act
    app(Chronicle::class)->recordError(new RuntimeException('boom'));

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($handler->getRecords()[0]->level)->toBe(MonologLevel::Error)
        ->and($entry['error.code'])->toBeNull()
        ->and($entry['error.message'])->toBe('boom')
        ->and($entry['error.why'])->toBeNull()
        ->and($entry['error.fix'])->toBeNull()
        ->and($entry['error.link'])->toBeNull();
});

it('merges exception context and lets call-site context win', function () {
    // Arrange
    $handler = chronicleTestHandler();

    $exception = new class extends RuntimeException implements StructuredException
    {
        use HasStructuredFields;

        public function __construct()
        {
            parent::__construct('Payment failed');

            $this->withError('billing.declined');
        }

        /**
         * @return array<string, mixed>
         */
        public function context(): array
        {
            return [
                'correlation_id' => 'pay_123',
                'shared' => 'from-exception',
            ];
        }
    };

    // Act
    app(Chronicle::class)->recordError($exception, fields: ['shared' => 'from-call-site']);

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['correlation_id'])->toBe('pay_123')
        ->and($entry['shared'])->toBe('from-call-site')
        ->and($entry['error.code'])->toBe('billing.declined');
});

it('throws in debug mode when an exception context is not an array', function () {
    // Arrange
    chronicleTestHandler();
    config()->set('app.debug', true);

    $exception = new class('boom') extends RuntimeException
    {
        public function context(): string
        {
            return 'order 7';
        }
    };

    // Act
    try {
        app(Chronicle::class)->recordError($exception);
    } catch (StructuredUnexpectedValueException $thrown) {
        // Assert
        expect($thrown->errorCode())->toBe('chronicle.error.invalid-context')
            ->and($thrown->getMessage())->toBe('RuntimeException@anonymous::context() returned string, not an array')
            ->and($thrown->errorFix())->toBe('Return an array from RuntimeException@anonymous::context()');

        return;
    }

    throw new UnexpectedValueException('recordError() should have thrown.');
});

it('records the error with the reason outside debug mode when an exception context is not an array', function () {
    // Arrange
    $handler = chronicleTestHandler();
    config()->set('app.debug', false);

    $exception = new class('boom') extends RuntimeException
    {
        public function context(): string
        {
            return 'order 7';
        }
    };

    // Act
    app(Chronicle::class)->recordError($exception);

    // Assert
    expect(chronicleEntries($handler)[0])
        ->toMatchArray([
            'type' => 'error',
            'error.message' => 'boom',
            'chronicle.context_error' => 'RuntimeException@anonymous::context() returned string, not an array',
        ]);
});

it('does not merge context from exceptions without a context method', function () {
    // Arrange
    $handler = chronicleTestHandler();

    // Act
    app(Chronicle::class)->recordError(new RuntimeException('boom'));

    // Assert
    expect(chronicleEntries($handler)[0])->not->toHaveKeys(['correlation_id']);
});

it('truncates long context values on a character boundary', function () {
    // Arrange
    $handler = chronicleTestHandler();

    $exception = new class('boom') extends RuntimeException
    {
        /**
         * @return array<string, mixed>
         */
        public function context(): array
        {
            // 1 + 600 × 2 = 1,201 bytes. Byte 1,000 is the first half of an ä.
            return ['raw' => 'a'.str_repeat('ä', 600)];
        }
    };

    // Act
    app(Chronicle::class)->recordError($exception);

    // Assert
    expect(chronicleEntries($handler)[0]['raw'])->toBe('a'.str_repeat('ä', 499));
});

it('accepts an explicit level in record error', function () {
    // Arrange
    $handler = chronicleTestHandler();

    // Act
    app(Chronicle::class)->recordError(new RuntimeException('boom'), level: Level::Critical);

    // Assert
    expect($handler->getRecords()[0]->level)->toBe(MonologLevel::Critical);
});

it('merges context accumulated through chronicle set into error events', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Context::add(['job.id' => 123, 'queue' => 'default']);

    // Act
    app(Chronicle::class)->recordError(new RuntimeException('boom'));

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['job.id'])->toBe(123)
        ->and($entry['queue'])->toBe('default')
        ->and($entry['error.message'])->toBe('boom');
});

it('collapses nested context arrays into dotted keys on error events', function () {
    // Arrange
    $handler = chronicleTestHandler();

    Context::add(['job' => ['id' => 123, 'tags' => ['a', 'b']]]);

    // Act
    app(Chronicle::class)->recordError(new RuntimeException('boom'));

    // Assert
    $entry = chronicleEntries($handler)[0];

    expect($entry['job.id'])->toBe(123)
        ->and($entry['job.tags'])->toBe('["a","b"]')
        ->and($entry['error.message'])->toBe('boom');
});

it('does not record errors when chronicle is disabled', function () {
    // Arrange
    $handler = chronicleTestHandler();
    config()->set('chronicle.enabled', false);

    // Act
    app(Chronicle::class)->recordError(new RuntimeException('boom'));

    // Assert
    expect($handler->getRecords())->toHaveCount(0);
});

it('takes fields before the level, like record', function () {
    // Arrange
    $handler = chronicleTestHandler();

    // Act
    app(Chronicle::class)->recordError(new RuntimeException('boom'), ['order.id' => 7], Level::Warning);

    // Assert
    expect($handler->getRecords()[0]->level)->toBe(MonologLevel::Warning)
        ->and(chronicleEntries($handler)[0]['order.id'])->toBe(7);
});
