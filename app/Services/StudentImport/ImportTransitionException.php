<?php

namespace App\Services\StudentImport;

use RuntimeException;

class ImportTransitionException extends RuntimeException
{
    public function __construct(public readonly string $from, public readonly string $to)
    {
        parent::__construct("Import batch cannot move from {$from} to {$to}.");
    }
}
