<?php

declare(strict_types=1);

namespace GNfsys\SheetImport\Examples;

use GNfsys\SheetImport\Importable;
use GNfsys\SheetImport\LibreOfficeOptions;
use GNfsys\SheetImport\OnEachRow;
use GNfsys\SheetImport\WithHeadingRow;
use GNfsys\SheetImport\WithLibreOfficeOptions;
use GNfsys\SheetImport\WithStartRow;
use GNfsys\SheetImport\WithTempDirectory;

/**
 * Not part of the library, a typical importer for PHPStan to analyse Importable with
 *
 * PHPStan only analyses a trait through the classes using it, the anonymous ones in the tests are final
 * and implement a few interfaces each, so most of import() is unreachable code there
 */
class UsersImport implements OnEachRow, WithHeadingRow, WithStartRow, WithTempDirectory, WithLibreOfficeOptions
{
    use Importable;

    /** @var list<array{row: array<mixed>, offset: int, index: int}> */
    public array $rows = [];

    /**
     * One based, like the row numbers shown in a spreadsheet.
     * Row 4 of the file holds the column names, it's never handed to onRow() and every row becomes name => value.
     * Rows above it would be handed over too, with the same names, if startRow() didn't skip them
     */
    public function headingRow(): int
    {
        return 4;
    }

    /**
     * One based, like the row numbers shown in a spreadsheet.
     * Rows before row 5 of the file are skipped, counted from the top wherever the heading row is.
     * The first onRow() call receives $offset 5 and $index 0
     */
    public function startRow(): int
    {
        return 5;
    }

    public function tempDirectory(): string
    {
        return sys_get_temp_dir() . '/users-import';
    }

    /**
     * Used instead of SheetImport::setLibreOfficeOptions(), by the import methods and by convertToCsv() without options
     */
    public function libreOfficeOptions(): LibreOfficeOptions
    {
        return new LibreOfficeOptions(executable: '/usr/bin/soffice');
    }

    public function onRow(array $row, int $offset, int $index): void
    {
        $this->rows[] = ['row' => $row, 'offset' => $offset, 'index' => $index];
    }
}
