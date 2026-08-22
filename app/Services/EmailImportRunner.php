<?php

declare(strict_types=1);

namespace Catch\Services;

use RuntimeException;

final class EmailImportRunner
{
    public function __construct(
        private readonly EmailImporter $importer,
        private readonly string $lockFile,
    ) {
    }

    /** @return array{processed: int, discarded: int, failed: int}|null */
    public function run(): ?array
    {
        $lock = fopen($this->lockFile, 'c');
        if ($lock === false) {
            throw new RuntimeException('The email importer lock could not be opened.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return null;
        }

        try {
            return $this->importer->run();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
