<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Larasell\Chronicle\Facades\Chronicle;
use Larasell\Chronicle\WideEvents\Enums\Level;
use Larasell\Chronicle\WideEvents\Requests\Middleware\LogRequests;
use PHPUnit\Framework\ExpectationFailedException;

it('records finished events instead of writing them', function () {
    // Arrange
    $handler = chronicleTestHandler();
    Chronicle::fake();

    // Act
    Chronicle::record('order.paid', ['order' => ['id' => 7], 'card_number' => '4242424242424242'], Level::Notice);

    // Assert
    expect($handler->getRecords())->toBe([]);

    Chronicle::assertRecorded('order.paid');
    Chronicle::assertRecorded('order.paid', fn (array $event, Level $level): bool => $event['order.id'] === 7
        && $event['card_number'] === '[redacted]'
        && $event['chronicle.redacted'] === '["card_number"]'
        && $level === Level::Notice);
    Chronicle::assertRecordedTimes('order.paid', 1);
    Chronicle::assertNotRecorded('order.refunded');
    Chronicle::assertNotRecorded('order.paid', fn (array $event): bool => $event['order.id'] === 8);
});

it('fails an assertion with the types it recorded', function () {
    // Arrange
    Chronicle::fake();
    Chronicle::record('order.paid');

    // Act & Assert
    expect(fn () => Chronicle::assertRecorded('order.refunded'))
        ->toThrow(ExpectationFailedException::class, 'Chronicle recorded no [order.refunded] event. It recorded: order.paid.')
        ->and(fn () => Chronicle::assertNothingRecorded())
        ->toThrow(ExpectationFailedException::class, 'It recorded: order.paid.')
        ->and(fn () => Chronicle::assertRecordedTimes('order.paid', 2))
        ->toThrow(ExpectationFailedException::class, 'Chronicle recorded [order.paid] 1 times, not 2.');
});

it('keeps fields added outside any scope, flattened and redacted', function () {
    // Arrange
    Chronicle::fake();

    // Act: what an action adds to the event of whatever request or job runs it.
    Chronicle::add('order', ['id' => 7, 'total_cents' => 4990]);
    Chronicle::add('payment.token', 'tok_1');
    Chronicle::add('coupon', null);

    // Assert
    Chronicle::assertAdded('order.id', 7);
    Chronicle::assertAdded('order.total_cents');
    Chronicle::assertAdded('payment.token', '[redacted]');
    Chronicle::assertAdded('coupon', null);
    Chronicle::assertAdded(fn (array $fields): bool => $fields['order.total_cents'] === 4990);
    Chronicle::assertNotAdded('order');
    Chronicle::assertNothingRecorded();

    expect(fn () => Chronicle::assertAdded('order.id', '7'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => Chronicle::assertAdded('order.status'))->toThrow(ExpectationFailedException::class, 'No [order.status] field was added to Chronicle.');
});

it('records the request event with the fields its code added', function () {
    // Arrange
    Chronicle::fake();

    Route::get('/checkout', function () {
        Chronicle::add('payment.provider', 'stripe');

        return 'ok';
    })->middleware(LogRequests::class);

    // Act
    $this->get('/checkout?step=payment');

    // Assert
    Chronicle::assertRecorded('request', fn (array $event): bool => $event['payment.provider'] === 'stripe'
        && $event['status'] === 200
        && $event['query.step'] === 'payment');
});

it('keeps the same fake when fake is called again', function () {
    // Arrange
    $fake = Chronicle::fake();
    Chronicle::record('order.paid');

    // Act
    $again = Chronicle::fake();

    // Assert
    expect($again)->toBe($fake)
        ->and($again->recorded('order.paid'))->toHaveCount(1);
});
