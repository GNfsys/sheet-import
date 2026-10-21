<?php

declare(strict_types=1);

namespace GNfsys\SheetImport;

interface WithTempDirectory
{
    /**
     * Base directory for temporary files, overrides SheetImport::setTempDirectory()
     */
    public function tempDirectory(): string;
}
