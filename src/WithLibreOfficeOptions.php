<?php

declare(strict_types=1);

namespace GNfsys\SheetImport;

interface WithLibreOfficeOptions
{
    /**
     * Options for the LibreOffice conversion, overrides SheetImport::setLibreOfficeOptions()
     */
    public function libreOfficeOptions(): LibreOfficeOptions;
}
