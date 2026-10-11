<?php

use Illuminate\Support\Facades\Log;
use Larasell\Chronicle\Chronicle;
use Larasell\Chronicle\ChronicleException;
use Larasell\Chronicle\HasStructuredError;
use Larasell\Chronicle\StructuredError;
use Larasell\Chronicle\Tests\Fixtures\CouldNotInviteMember;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function errorTestHandler(): TestHandler
{
    config()->set('chronicle.enabled', true);
    config()->set('chronicle.channel', 'chronicle_error_test');
    config()->set('logging.channels.chronicle_error_test', [
        'driver' => 'monolog',
        'handler' => TestHandler::class,
    ]);

    $handler = Log::channel('chronicle_error_test')->getHandlers()[0];

    expect($handler)->toBeInstanceOf(TestHandler::class);

    return $handler;
}

function errorEntries(TestHandler $handler): array
{
    return array_map(
        fn (LogRecord $record) => json_decode($record->message, true),
        $handler->getRecords(),
    );
}

it('records structured fields for exceptions implementing the contract', function () {
    $handler = errorTestHandler();

    $previous = new RuntimeException('network down');
    $e = ChronicleException::make(
        code: 'billing.declined',
        message: 'Payment failed',
        why: 'Issuer declined the charge',
        fix: 'Try a different card',
        link: 'https://docs.example.com/errors#billing-declined',
        previous: $previous,
    );

    app(Chronicle::class)->recordError($e);

    $entry = errorEntries($handler)[0];

    expect($e->getPrevious())->toBe($previous)
        ->and($e->errorCode())->toBe('billing.declined')
        ->and($entry['type'])->toBe('error')
        ->and($entry['error.code'])->toBe('billing.declined')
        ->and($entry['error.message'])->toBe('Payment failed')
        ->and($entry['error.why'])->toBe('Issuer declined the charge')
        ->and($entry['error.fix'])->toBe('Try a different card')
        ->and($entry['error.link'])->toBe('https://docs.example.com/errors#billing-declined')
        ->and($entry['error.class'])->toBe(ChronicleException::class)
        ->and($entry['error.file'])->toBeString()
        ->and($entry['error.status'])->toBeNull();
});

it('records domain exceptions built through the structured error constructor', function () {
    $handler = errorTestHandler();

    app(Chronicle::class)->recordError(CouldNotInviteMember::emailAlreadyInvited('nils@example.com', 'acme'));

    $entry = errorEntries($handler)[0];

    expect($entry['error.code'])->toBe('teams.could-not-invite-member.email-already-invited')
        ->and($entry['error.message'])->toBe('Could not invite nils@example.com to team acme: they already have a pending invitation.')
        ->and($entry['error.why'])->toBe('A pending invitation for nils@example.com already exists in team acme')
        ->and($entry['error.fix'])->toBe('Resend the existing invitation or remove it first')
        ->and($entry['error.class'])->toBe(CouldNotInviteMember::class);
});

it('degrades to a stable shape for plain throwables', function () {
    $handler = errorTestHandler();

    app(Chronicle::class)->recordError(new RuntimeException('boom'));

    $entry = errorEntries($handler)[0];

    expect($handler->getRecords()[0]->level)->toBe(Level::Error)
        ->and($entry['error.code'])->toBeNull()
        ->and($entry['error.message'])->toBe('boom')
        ->and($entry['error.why'])->toBeNull()
        ->and($entry['error.fix'])->toBeNull()
        ->and($entry['error.link'])->toBeNull()
        ->and($entry['error.class'])->toBe(RuntimeException::class)
        ->and($entry['error.file'])->toBeString();
});

it('extracts the http status from http exceptions', function () {
    $handler = errorTestHandler();

    app(Chronicle::class)->recordError(new NotFoundHttpException('Not found'));

    expect(errorEntries($handler)[0]['error.status'])->toBe(404);
});

it('extracts the http status from exceptions carrying a response', function () {
    $handler = errorTestHandler();

    $e = new class('boom') extends RuntimeException
    {
        public function getResponse(): Response
        {
            return new Response('teapot', 418);
        }
    };

    app(Chronicle::class)->recordError($e);

    expect(errorEntries($handler)[0]['error.status'])->toBe(418);
});

it('merges exception context and lets call-site context win', function () {
    $handler = errorTestHandler();

    $e = new class('Payment failed') extends RuntimeException implements StructuredError
    {
        use HasStructuredError;

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

    $entry = errorEntries($handler)[0];

    expect($entry['correlation_id'])->toBe('pay_123')
        ->and($entry['shared'])->toBe('from-call-site')
        ->and($entry['error.code'])->toBe('billing.declined');
});

it('does not merge context from exceptions without a context method', function () {
    $handler = errorTestHandler();

    app(Chronicle::class)->recordError(new RuntimeException('boom'));

    expect(errorEntries($handler)[0])->not->toHaveKeys(['correlation_id']);
});

it('truncates long context values', function () {
    $handler = errorTestHandler();

    $e = new class('boom') extends RuntimeException
    {
        public function context(): array
        {
            return ['raw' => str_repeat('a', 1500)];
        }
    };

    app(Chronicle::class)->recordError($e);

    expect(strlen(errorEntries($handler)[0]['raw']))->toBe(1000);
});

it('accepts an explicit level in record error', function () {
    $handler = errorTestHandler();

    app(Chronicle::class)->recordError(new RuntimeException('boom'), Level::Critical);

    expect($handler->getRecords()[0]->level)->toBe(Level::Critical);
});

it('does not record errors when chronicle is disabled', function () {
    $handler = errorTestHandler();
    config()->set('chronicle.enabled', false);

    app(Chronicle::class)->recordError(new RuntimeException('boom'));

    expect($handler->getRecords())->toHaveCount(0);
});
