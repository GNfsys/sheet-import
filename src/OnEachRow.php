<?php

declare(strict_types=1);

namespace GNfsys\SheetImport;

interface OnEachRow
{
    /**
     * @param array<mixed> $row
     * @param int          $offset Row number in the file, one based
     * @param int          $index  Position among the imported rows, zero based
     */
    public function onRow(array $row, int $offset, int $index): void;
}
