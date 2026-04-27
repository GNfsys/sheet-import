<?php

declare(strict_types=1);

namespace GNfsys\SheetImport;

interface WithStartRow
{
    public function startRow(): int;
}
