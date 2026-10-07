<?php

use App\Services\StudentImport\BatchStateMachine as M;
use App\Services\StudentImport\ImportTransitionException;

it('allows exactly the approved forward transitions', function (string $from, string $to) {
    expect(M::canTransition($from, $to, false))->toBeTrue();
})->with([
    [M::STAGED, M::VALIDATING], [M::STAGED, M::FAILED],
    [M::VALIDATING, M::PREVIEWED], [M::VALIDATING, M::FAILED],
    [M::PREVIEWED, M::CONFIRMED], [M::PREVIEWED, M::VALIDATING],
    [M::CONFIRMED, M::PROCESSING], [M::CONFIRMED, M::FAILED],
    [M::PROCESSING, M::COMPLETED], [M::PROCESSING, M::FAILED],
]);

it('rejects every other transition', function () {
    $allowed = [
        'STAGED>VALIDATING', 'STAGED>FAILED', 'VALIDATING>PREVIEWED', 'VALIDATING>FAILED', 'PREVIEWED>CONFIRMED',
        'PREVIEWED>VALIDATING', 'CONFIRMED>PROCESSING', 'CONFIRMED>FAILED', 'PROCESSING>COMPLETED', 'PROCESSING>FAILED',
        'FAILED>VALIDATING', 'FAILED>PROCESSING',
    ];

    foreach (M::STATUSES as $from) {
        foreach (M::STATUSES as $to) {
            if (! in_array("$from>$to", $allowed, true)) {
                expect(M::canTransition($from, $to, true))->toBeFalse("$from>$to must be rejected (confirmed)");
                expect(M::canTransition($from, $to, false))->toBeFalse("$from>$to must be rejected (unconfirmed)");
            }
        }
    }
});

it('never leaves COMPLETED', function () {
    foreach (M::STATUSES as $to) {
        expect(M::canTransition(M::COMPLETED, $to, true))->toBeFalse();
    }
});

it('cannot skip validation, preview or confirmation', function () {
    expect(M::canTransition(M::STAGED, M::CONFIRMED, false))->toBeFalse();
    expect(M::canTransition(M::STAGED, M::PROCESSING, false))->toBeFalse();
    expect(M::canTransition(M::VALIDATING, M::CONFIRMED, false))->toBeFalse();
    expect(M::canTransition(M::PREVIEWED, M::PROCESSING, false))->toBeFalse();
    expect(M::canTransition(M::PREVIEWED, M::COMPLETED, false))->toBeFalse();
});

it('recovers FAILED toward validation only before confirmation and toward processing only after', function () {
    expect(M::canTransition(M::FAILED, M::VALIDATING, false))->toBeTrue();
    expect(M::canTransition(M::FAILED, M::PROCESSING, false))->toBeFalse();
    expect(M::canTransition(M::FAILED, M::PROCESSING, true))->toBeTrue();
    expect(M::canTransition(M::FAILED, M::VALIDATING, true))->toBeFalse();
});

it('throws a typed exception for an illegal transition', function () {
    expect(fn () => M::assertAllowed(M::COMPLETED, M::PROCESSING, true))->toThrow(ImportTransitionException::class);
    M::assertAllowed(M::STAGED, M::VALIDATING, false);
    expect(true)->toBeTrue();
});
