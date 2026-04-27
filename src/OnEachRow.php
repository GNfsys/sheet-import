<?php

declare(strict_types=1);

namespace GNfsys\SheetImport;

interface OnEachRow
{
    /**
     * @param  array<mixed>  $row
     */
    public function onRow(array $row, int $offset): void;
}
