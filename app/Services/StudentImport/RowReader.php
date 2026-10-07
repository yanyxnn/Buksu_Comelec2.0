<?php

namespace App\Services\StudentImport;

use Generator;

interface RowReader
{
    /**
     * Streams the non-blank rows of the file.
     *
     * @return Generator<int, list<string|null>> source row number (1-based, as in the file) => raw cells
     *
     * @throws SourceFileException
     */
    public function rows(string $path): Generator;
}
