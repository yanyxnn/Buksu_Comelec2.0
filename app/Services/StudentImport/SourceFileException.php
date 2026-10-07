<?php

namespace App\Services\StudentImport;

use RuntimeException;

/** File-level problem (the file as a whole cannot be used). The code is coarse and contains no row data. */
class SourceFileException extends RuntimeException
{
    public const UNSUPPORTED_FILE_TYPE = 'UNSUPPORTED_FILE_TYPE';

    public const FILE_TOO_LARGE = 'FILE_TOO_LARGE';

    public const FILE_UNREADABLE = 'FILE_UNREADABLE';

    public const EMPTY_FILE = 'EMPTY_FILE';

    public const INVALID_ENCODING = 'INVALID_ENCODING';

    public const MISSING_REQUIRED_COLUMNS = 'MISSING_REQUIRED_COLUMNS';

    public const DUPLICATE_COLUMNS = 'DUPLICATE_COLUMNS';

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
