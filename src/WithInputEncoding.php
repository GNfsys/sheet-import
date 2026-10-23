<?php

declare(strict_types=1);

namespace GNfsys\SheetImport;

interface WithInputEncoding
{
    /**
     * Encoding of the csv files read as they are, converted to UTF-8. One of mb_list_encodings(), case-insensitive
     */
    public function inputEncoding(): string;
}
