<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Context;
use Larasell\Chronicle\Chronicle;
use Larasell\Chronicle\Exceptions\HasStructuredException;
use Larasell\Chronicle\Exceptions\StructuredException;
use Larasell\Chronicle\Tests\Fixtures\CouldNotInviteMember;
use Larasell\Chronicle\Tests\Fixtures\PaymentDeclined;
use Monolog\Level;
use RuntimeException;

it('records structured fields for exceptions implementing the contract', function () {
    $handler = chronicleTestHandler();

    $previous = new RuntimeException('network down');
    $e = PaymentDeclined::byIssuer($previous);

    app(Chronicle::class)->recordError($e);

    $entry = chronicleEntries($handler)[0];

    expect($e->getPrevious())->toBe($previous)
        ->and($e->errorCode())->toBe('billing.declined')
        ->and($entry['type'])->toBe('error')
        ->and($entry['error.code'])->toBe('billing.declined')
        ->and($entry['error.message'])->toBe('Payment failed')
        ->and($entry['error.why'])->toBe('Issuer declined the charge')
        ->and($entry['error.fix'])->toBe('Try a different card')
        ->and($entry['error.link'])->toBe('https://docs.example.com/errors#billing-declined');
});

it('records domain exceptions built through the structured error constructor', function () {
    $handler = chronicleTestHandler();

    app(Chronicle::class)->recordError(CouldNotInviteMember::emailAlreadyInvited('nils@example.com', 'acme'));

    $entry = chronicleEntries($handler)[0];

    expect($entry['error.code'])->toBe('teams.could-not-invite-member.email-already-invited')
        ->and($entry['error.message'])->toBe('Could not invite nils@example.com to team acme: they already have a pending invitation.')
        ->and($entry['error.why'])->toBe('A pending invitation for nils@example.com already exists in team acme')
        ->and($entry['error.fix'])->toBe('Resend the existing invitation or remove it first');
});

it('records structured exceptions thrown inline', function (string $class, string $base) {
    $handler = chronicleTestHandler();
    $previous = new RuntimeException('network down');

    if (! is_a($class, Throwable::class, true)) {
        throw new UnexpectedValueException("The dataset must name a throwable class, [{$class}] given.");
    }

    if (! class_exists($base)) {
        throw new UnexpectedValueException("The dataset must name an SPL base class, [{$base}] given.");
    }

    try {
        throw new $class(
            code: 'billing.declined',
            message: 'Payment failed',
            why: 'Issuer declined the charge',
            fix: 'Try a different card',
            link: 'https://docs.example.com/errors#billing-declined',
            previous: $previous,
        );
    } catch (StructuredException $e) {
        app(Chronicle::class)->recordError($e);
    }

    $entry = chronicleEntries($handler)[0];

    expect($e)->toBeInstanceOf($base)
        ->and($e->getPrevious())->toBe($previous)
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
    $handler = chronicleTestHandler();

    app(Chronicle::class)->recordError(new RuntimeException('boom'));

    $entry = chronicleEntries($handler)[0];

    expect($handler->getRecords()[0]->level)->toBe(Level::Error)
        ->and($entry['error.code'])->toBeNull()
        ->and($entry['error.message'])->toBe('boom')
        ->and($entry['error.why'])->toBeNull()
        ->and($entry['error.fix'])->toBeNull()
        ->and($entry['error.link'])->toBeNull();
});

it('merges exception context and lets call-site context win', function () {
    $handler = chronicleTestHandler();

    $e = new class('Payment failed') extends RuntimeException implements StructuredException
    {
        use HasStructuredException;

        /** @return array<string, string> */
        public function context(): array
        {
            return [
                'correlation_id' => 'pay_123',
                'shared' => 'from-exception',
            ];
        }
    };

    $e->errorCode = 'billing.declined';

    app(Chronicle::class)->recordError($e, context: ['shared' => 'from-call-site']);

    $entry = chronicleEntries($handler)[0];

    expect($entry['correlation_id'])->toBe('pay_123')
        ->and($entry['shared'])->toBe('from-call-site')
        ->and($entry['error.code'])->toBe('billing.declined');
});

it('does not merge context from exceptions without a context method', function () {
    $handler = chronicleTestHandler();

    app(Chronicle::class)->recordError(new RuntimeException('boom'));

    expect(chronicleEntries($handler)[0])->not->toHaveKeys(['correlation_id']);
});

it('truncates long context values', function () {
    $handler = chronicleTestHandler();

    $e = new class('boom') extends RuntimeException
    {
        /** @return array<string, string> */
        public function context(): array
        {
            return ['raw' => str_repeat('a', 1500)];
        }
    };

    app(Chronicle::class)->recordError($e);

    expect(strlen(Arr::string(chronicleEntries($handler)[0], 'raw')))->toBe(1000);
});

it('accepts an explicit level in record error', function () {
    $handler = chronicleTestHandler();

    app(Chronicle::class)->recordError(new RuntimeException('boom'), Level::Critical);

    expect($handler->getRecords()[0]->level)->toBe(Level::Critical);
});

it('merges context accumulated through chronicle set into error events', function () {
    $handler = chronicleTestHandler();

    Context::add(['job.id' => 123, 'queue' => 'default']);

    app(Chronicle::class)->recordError(new RuntimeException('boom'));

    $entry = chronicleEntries($handler)[0];

    expect($entry['job.id'])->toBe(123)
        ->and($entry['queue'])->toBe('default')
        ->and($entry['error.message'])->toBe('boom');
});

it('collapses nested context arrays into dotted keys on error events', function () {
    $handler = chronicleTestHandler();

    Context::add(['job' => ['id' => 123, 'tags' => ['a', 'b']]]);

    app(Chronicle::class)->recordError(new RuntimeException('boom'));

    $entry = chronicleEntries($handler)[0];

    expect($entry['job.id'])->toBe(123)
        ->and($entry['job.tags'])->toBe('["a","b"]')
        ->and($entry['error.message'])->toBe('boom');
});

it('does not record errors when chronicle is disabled', function () {
    $handler = chronicleTestHandler();
    config()->set('chronicle.enabled', false);

    app(Chronicle::class)->recordError(new RuntimeException('boom'));

    expect($handler->getRecords())->toHaveCount(0);
});
