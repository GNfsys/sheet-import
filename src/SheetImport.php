<?php

declare(strict_types=1);

namespace GNfsys\SheetImport;

final class SheetImport
{
    private static ?string $tempDirectory = null;

    private static ?LibreOfficeOptions $libreOfficeOptions = null;

    /**
     * Set the base directory for temporary files (converted csv, LibreOffice profile)
     * used by every importer that doesn't implement WithTempDirectory.
     *
     * Pass null to restore the system default.
     */
    public static function setTempDirectory(?string $path): void
    {
        self::$tempDirectory = $path;
    }

    public static function getTempDirectory(): string
    {
        return self::$tempDirectory ?? sys_get_temp_dir();
    }

    /**
     * Set the LibreOffice options, e.g. the path of the binary,
     * used by every importer that doesn't implement WithLibreOfficeOptions.
     *
     * Pass null to restore the defaults.
     */
    public static function setLibreOfficeOptions(?LibreOfficeOptions $options): void
    {
        self::$libreOfficeOptions = $options;
    }

    public static function getLibreOfficeOptions(): LibreOfficeOptions
    {
        return self::$libreOfficeOptions ?? new LibreOfficeOptions();
    }
}
