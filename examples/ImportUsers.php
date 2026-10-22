<?php

declare(strict_types=1);

namespace GNfsys\SheetImport\Examples;

use GNfsys\SheetImport\Common;
use GNfsys\SheetImport\LibreOfficeOptions;
use Throwable;

/**
 * Not part of the library, the ways to run UsersImport, for PHPStan to check the public API from the caller side
 */
final class ImportUsers
{
    /**
     * @return list<array{row: array<mixed>, offset: int, index: int}>
     *
     * @throws Throwable
     */
    public function fromFile(string $path): array
    {
        $importer = new UsersImport();
        $importer->importFromFile($path);

        return $importer->rows;
    }

    /**
     * @return list<array{row: array<mixed>, offset: int, index: int}>
     *
     * @throws Throwable
     */
    public function fromString(string $content): array
    {
        $importer = new UsersImport();
        $importer->importFromString($content);

        return $importer->rows;
    }

    /**
     * A path that doesn't exist is read as csv content
     *
     * @return list<array{row: array<mixed>, offset: int, index: int}>
     *
     * @throws Throwable
     */
    public function fromFileOrString(string $pathOrContent): array
    {
        $importer = new UsersImport();
        $importer->import($pathOrContent);

        return $importer->rows;
    }

    /**
     * Converting manually, e.g. with options for this file only, leaves the temporary files to the caller
     *
     * @return list<array{row: array<mixed>, offset: int, index: int}>
     *
     * @throws Throwable
     */
    public function fromFileWithLibreOffice(string $path, string $libreOfficeBinary): array
    {
        $importer = new UsersImport();

        try {
            $csvPath = $importer->convertToCsv($path, new LibreOfficeOptions(executable: $libreOfficeBinary));

            // Already a csv, so it's read where it is
            $importer->importFromFile($csvPath);
        } finally {
            $importer->cleanupTempFiles();
        }

        return $importer->rows;
    }

    /**
     * @return list<array{row: array<mixed>, offset: int, index: int}>
     *
     * @throws Throwable
     */
    public function fromFileWithUppercaseColumnNames(string $path): array
    {
        $importer = new UsersImport();
        $importer->importFromFile(
            $path,
            // Replaces the default formatting, so duplicates have to be renamed here too
            fn (array $header): array => Common::handleDuplicates(array_map(strtoupper(...), $header))
        );

        return $importer->rows;
    }
}
