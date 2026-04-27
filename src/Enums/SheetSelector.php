<?php

declare(strict_types=1);

namespace GNfsys\SheetImport\Enums;

enum SheetSelector: string
{
    /**
     * Default behaviour, first sheet from command line, or current sheet in macro filter options.
     */
    case Default = '0';
}
