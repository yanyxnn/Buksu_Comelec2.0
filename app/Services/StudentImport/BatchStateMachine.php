<?php

namespace App\Services\StudentImport;

/**
 * The only definition of legal import-batch status transitions. Controllers and components
 * never set `status`; ImportBatchService applies a transition only after this approves it.
 */
final class BatchStateMachine
{
    public const STAGED = 'STAGED';

    public const VALIDATING = 'VALIDATING';

    public const PREVIEWED = 'PREVIEWED';

    public const CONFIRMED = 'CONFIRMED';

    public const PROCESSING = 'PROCESSING';

    public const COMPLETED = 'COMPLETED';

    public const FAILED = 'FAILED';

    public const STATUSES = [
        self::STAGED, self::VALIDATING, self::PREVIEWED, self::CONFIRMED,
        self::PROCESSING, self::COMPLETED, self::FAILED,
    ];

    private const ALLOWED = [
        self::STAGED => [self::VALIDATING, self::FAILED],
        self::VALIDATING => [self::PREVIEWED, self::FAILED],
        self::PREVIEWED => [self::VALIDATING, self::CONFIRMED],
        self::CONFIRMED => [self::PROCESSING, self::FAILED],
        self::PROCESSING => [self::COMPLETED, self::FAILED],
        // FAILED is recoverable: before confirmation it is re-validated; after confirmation
        // processing resumes. Never the other way round.
        self::FAILED => [self::VALIDATING, self::PROCESSING],
        self::COMPLETED => [],
    ];

    public static function canTransition(string $from, string $to, bool $wasConfirmed): bool
    {
        if (! in_array($to, self::ALLOWED[$from] ?? [], true)) {
            return false;
        }

        if ($from === self::FAILED) {
            return $to === self::PROCESSING ? $wasConfirmed : ! $wasConfirmed;
        }

        return true;
    }

    /**
     * @throws ImportTransitionException
     */
    public static function assertAllowed(string $from, string $to, bool $wasConfirmed): void
    {
        if (! self::canTransition($from, $to, $wasConfirmed)) {
            throw new ImportTransitionException($from, $to);
        }
    }
}
