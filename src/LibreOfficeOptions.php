<?php

declare(strict_types=1);

namespace GNfsys\SheetImport;

final class LibreOfficeOptions
{
    /**
     * The default for $exportSheets, any other sheet is chosen by its number starting from 1.
     * Every sheet at once (-1) isn't supported, an import reads a single csv
     */
    public const string FIRST_SHEET = '0';

    private const string COMMA = '44';
    private const string QUOTES = '34';
    private const string UTF8 = '76';
    private const string SYSTEM_LOCALE = '0';

    private int $startLine = 1 {
        set => $value;
    }

    public function __construct(
        public string $executable = 'libreoffice',
        public string $locale = 'it_IT.UTF-8', // TODO: See below
        public string $fieldSeparator = self::COMMA,
        public string $textDelimiter = self::QUOTES,
        public string $charset = self::UTF8,
        public string $cellFormat = '',
        public string $lang = self::SYSTEM_LOCALE, // TODO: Decide whether to use this or LANG/LC_ALL ($locale)
        public bool   $quotedFieldAsText = true, // false
        public bool   $detectSpecialNumbers = false, // true
        public bool   $saveAsShown = false, // true
        public bool   $exportCellFormulas = false,
        public bool   $trimSpaces = false,
        public string $exportSheets = self::FIRST_SHEET,
        public bool   $importAsFormulas = false,
        public bool   $includeBom = false,
        public bool   $detectScientificNotation = false, // true
    ) {
        //
    }

    private function boolToStr(bool $var): string
    {
        return $var ? 'true' : 'false';
    }

    public function getBinaryPath(): string
    {
        return $this->executable;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getFilterOptions(): string
    {
        return $this->fieldSeparator
            . ',' . $this->textDelimiter
            . ',' . $this->charset
            . ',' . $this->startLine
            . ',' . $this->cellFormat
            . ',' . $this->lang
            . ',' . $this->boolToStr($this->quotedFieldAsText)
            . ',' . $this->boolToStr($this->detectSpecialNumbers)
            . ',' . $this->boolToStr($this->saveAsShown)
            . ',' . $this->boolToStr($this->exportCellFormulas)
            . ',' . $this->boolToStr($this->trimSpaces)
            . ',' . $this->exportSheets
            . ',' . $this->boolToStr($this->importAsFormulas)
            . ',' . $this->boolToStr($this->includeBom)
            . ',' . $this->boolToStr($this->detectScientificNotation);
    }
}
