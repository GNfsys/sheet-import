<?php

declare(strict_types=1);

namespace GNfsys\SheetImport;

use League\Csv\TabularDataReader;

interface ToIterator
{
    /**
     * @param TabularDataReader<array<mixed>> $records
     */
    public function handleIterator(TabularDataReader $records): void;
}
