<?php

declare(strict_types=1);

namespace GNfsys\SheetImport;

use Bakame\TabularData\HtmlTable\Parser;
use Bakame\TabularData\HtmlTable\ParserError;
use GNfsys\SheetImport\Exceptions\HtmlConversionException;
use GNfsys\SheetImport\Exceptions\SheetImportException;
use League\Csv\CannotInsertRecord;
use League\Csv\Exception;
use League\Csv\Info;
use League\Csv\InvalidArgument;
use League\Csv\Reader;
use League\Csv\Statement;
use League\Csv\SyntaxError;
use League\Csv\UnavailableStream;
use League\Csv\Writer;
use Random\RandomException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

trait Importable
{
    /** @var list<string> */
    private array $tmpDirs = [];

    /**
     * @throws UnavailableStream
     * @throws SyntaxError
     * @throws RandomException
     * @throws CannotInsertRecord
     * @throws Exception
     * @throws ParserError
     */
    private function convertHtmlToCsv(string $path, string $dest): void
    {
        // Every row as it is, the heading row is chosen later like for any other file
        $table = Parser::new()
            ->ignoreTableHeader()
            ->includeAllSections()
            ->parseFile($path);

        $writer = Writer::from($dest, 'w+');

        $writer->insertAll($table);
    }

    /**
     * Import a file, converted to csv first unless it's already one
     *
     * @param (callable(array<string>): array<string>)|null $headerFormat
     *
     * @throws SheetImportException
     * @throws RandomException
     * @throws InvalidArgument
     * @throws UnavailableStream
     * @throws SyntaxError
     * @throws Exception
     * @throws Throwable
     */
    public function importFromFile(string $path, ?callable $headerFormat = null): void
    {
        if (!file_exists($path)) {
            throw new SheetImportException("\"{$path}\" does not exist.");
        }

        if (!is_file($path)) {
            throw new SheetImportException("\"{$path}\" is not a file.");
        }

        if (!is_readable($path)) {
            throw new SheetImportException("File \"{$path}\" is not readable.");
        }

        $this->runImport($headerFormat, path: $path);
    }

    /**
     * Import csv content
     *
     * @param (callable(array<string>): array<string>)|null $headerFormat
     *
     * @throws SheetImportException
     * @throws RandomException
     * @throws InvalidArgument
     * @throws UnavailableStream
     * @throws SyntaxError
     * @throws Exception
     * @throws Throwable
     */
    public function importFromString(string $content, ?callable $headerFormat = null): void
    {
        if ($content === '') {
            throw new SheetImportException('Content must not be empty.');
        }

        $this->runImport($headerFormat, content: $content);
    }

    /**
     * Import a file or, when $file isn't the path of an existing file, csv content
     *
     * @param (callable(array<string>): array<string>)|null $headerFormat
     *
     * @throws SheetImportException
     * @throws RandomException
     * @throws InvalidArgument
     * @throws UnavailableStream
     * @throws SyntaxError
     * @throws Exception
     * @throws Throwable
     */
    public function import(string $file, ?callable $headerFormat = null): void
    {
        if (file_exists($file)) {
            $this->runImport($headerFormat, path: $file);
        } else {
            $this->runImport($headerFormat, content: $file);
        }
    }

    /**
     * Only removes the temporary directory it creates, the ones from manual convertToCsv() calls are left to the caller
     *
     * @param (callable(array<string>): array<string>)|null $headerFormat
     *
     * @throws SheetImportException
     * @throws RandomException
     * @throws InvalidArgument
     * @throws UnavailableStream
     * @throws SyntaxError
     * @throws Exception
     * @throws Throwable
     */
    private function runImport(?callable $headerFormat, string $path = '', string $content = ''): void
    {
        $startRow = 0;
        if ($this instanceof WithStartRow) {
            // Relative to the file like the heading row, which is skipped wherever it is
            $startRow = $this->startRow() - 1;

            if ($startRow < 0) {
                throw new SheetImportException('Row offset must be greater or equal to "0".');
            }
        }

        $headerOffset = 0;
        if ($this instanceof WithHeadingRow && is_callable([$this, 'headingRow'])) {
            $headerOffset = ((int) $this->headingRow()) - 1;

            if ($headerOffset < 0) {
                throw new SheetImportException("Heading row offset must be greater than or equal to \"0\". It's \"$headerOffset\".");
            }

            // if ($this->header_offset < $this->start_row) {
            //    throw new SheetImportException('Heading row offset must be greater than or equal to the start offset.');
            // }
        }

        $tmpDir = null;

        try {
            if ($path !== '') {
                [$csvPath, $tmpDir] = $this->convertToCsvInTempDirectory($path, self::defaultLibreOfficeOptions($this));
                $reader = $this->loadCsv(path: $csvPath);
            } else {
                $reader = $this->loadCsv(content: $content);
            }

            if ($this instanceof WithHeadingRow) {
                $reader->setHeaderOffset($headerOffset);
            }

            $header = $reader->getHeader();
            if ($this instanceof WithHeadingRow) {
                $header = $headerFormat ? $headerFormat($header) : Common::prepareHeader($header);
            }

            if (!is_array($header)) {
                throw new SheetImportException('Header is expected to be an array');
            }

            // Use $header values as array keys, records keep their offset in the file as key
            // while the heading row and empty records are left out, so filter on the key
            $records = new Statement()
                ->where(fn (array $record, int $offset): bool => $offset >= $startRow)
                ->process($reader, $header);

            if ($this instanceof ToIterator) {
                $this->handleIterator($records);
            } elseif ($this instanceof OnEachRow) {
                $index = 0;
                foreach ($records as $offset => $record) {
                    // From zero based to one based
                    $this->onRow($record, $offset + 1, $index++);
                }
            }
        } finally {
            if ($tmpDir !== null) {
                self::removeDirectory($tmpDir);
            }
        }
    }

    /**
     * Delete the temporary directories created by calling convertToCsv(), the import methods remove their own
     */
    public function cleanupTempFiles(): void
    {
        foreach ($this->tmpDirs as $dir) {
            self::removeDirectory($dir);
        }

        $this->tmpDirs = [];
    }

    /**
     * Best effort, must not throw as it runs while another exception may be propagating
     */
    private static function removeDirectory(string $dir): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }

        $dir = rtrim($dir, '/');

        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $path = "$dir/$entry";

            if (is_dir($path) && !is_link($path)) {
                self::removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * Takes the importer as a plain object, PHPStan analyses $this as each class using the trait
     * and can't type tempDirectory() for the ones not implementing WithTempDirectory
     */
    private static function baseTempDirectory(object $importer): string
    {
        return $importer instanceof WithTempDirectory ? $importer->tempDirectory() : SheetImport::getTempDirectory();
    }

    /**
     * Takes the importer as a plain object for the same reason as baseTempDirectory()
     */
    private static function defaultLibreOfficeOptions(object $importer): LibreOfficeOptions
    {
        return $importer instanceof WithLibreOfficeOptions ? $importer->libreOfficeOptions() : SheetImport::getLibreOfficeOptions();
    }

    /**
     * Create a unique directory for a single conversion, also used as LibreOffice's HOME
     * so concurrent conversions don't share (and lock) the same profile
     *
     * @throws SheetImportException
     * @throws RandomException
     */
    private function createTempDirectory(): string
    {
        $baseDir = self::baseTempDirectory($this);
        if ($baseDir === '') {
            throw new SheetImportException('Temporary directory must not be empty.');
        }

        $tmpDir = rtrim($baseDir, '/') . '/phpship' . bin2hex(random_bytes(6));

        // Missing parent directories are created, e.g. on a fresh deploy
        if (!@mkdir($tmpDir, 0770, true)) {
            throw new SheetImportException("Could not create temporary directory \"{$tmpDir}\".");
        }

        // LibreOffice needs an absolute HOME and --outdir
        $realPath = realpath($tmpDir);
        if ($realPath === false) {
            self::removeDirectory($tmpDir);

            throw new SheetImportException("Could not resolve temporary directory \"{$tmpDir}\".");
        }

        return $realPath . '/';
    }

    /**
     * The returned csv lives in a temporary directory, call cleanupTempFiles() when done with it.
     * Without $libreOfficeOptions the importer's (WithLibreOfficeOptions) or the global ones are used
     *
     * @throws SheetImportException
     * @throws RandomException
     * @throws HtmlConversionException
     * @throws Throwable
     */
    public function convertToCsv(string $path, ?LibreOfficeOptions $libreOfficeOptions = null): string
    {
        [$csvFilepath, $tmpDir] = $this->convertToCsvInTempDirectory(
            $path,
            $libreOfficeOptions ?? self::defaultLibreOfficeOptions($this)
        );

        if ($tmpDir !== null) {
            $this->tmpDirs[] = $tmpDir;
        }

        return $csvFilepath;
    }

    /**
     * Doesn't record the temporary directory, the caller owns it
     *
     * @return array{string, ?string} The csv path and the temporary directory holding it, null when the file is already a csv
     *
     * @throws SheetImportException
     * @throws RandomException
     * @throws HtmlConversionException
     * @throws Throwable
     */
    private function convertToCsvInTempDirectory(string $path, LibreOfficeOptions $libreOfficeOptions): array
    {
        if (!is_readable($path)) {
            throw new SheetImportException("Could not get the contents of the file \"{$path}\".");
        }

        $mimeTypeError = fn () => throw new SheetImportException("Could not detect the mime type of the file \"{$path}\".");
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($fileInfo === false) {
            $mimeTypeError();
        }

        $mimeType = finfo_file($fileInfo, $path);
        if ($mimeType === false) {
            $mimeTypeError();
        }

        if ($mimeType === 'text/plain' || $mimeType === 'text/csv') {
            return [$path, null];
        }

        // Fake xls handling
        $isHtml = $mimeType === 'text/html';

        // Before LibreOffice runs, every sheet at once (-1) would be one csv per sheet
        if (!$isHtml && preg_match('/^\d+$/D', $libreOfficeOptions->exportSheets) !== 1) {
            throw new SheetImportException(
                "Can't export sheet \"{$libreOfficeOptions->exportSheets}\", use 0 for the first sheet or the number of a sheet starting from 1."
            );
        }

        $tmpDir = $this->createTempDirectory();

        try {
            if ($isHtml) {
                $csvFilepath = $tmpDir . pathinfo($path, PATHINFO_FILENAME) . '.csv';

                try {
                    $this->convertHtmlToCsv($path, $csvFilepath);
                } catch (Exception|ParserError $exception) {
                    throw new HtmlConversionException('Failed to convert Html file', previous: $exception);
                }
            } else {
                $csvFilepath = $this->convertWithLibreOffice($path, $tmpDir, $libreOfficeOptions);
            }
        } catch (Throwable $throwable) {
            self::removeDirectory($tmpDir);

            throw $throwable;
        }

        return [$csvFilepath, $tmpDir];
    }

    /**
     * @return string The converted csv
     *
     * @throws SheetImportException
     */
    private function convertWithLibreOffice(string $path, string $tmpDir, LibreOfficeOptions $libreOfficeOptions): string
    {
        $conversionProcess = new Process(
            [
                $libreOfficeOptions->getBinaryPath(),
                '--headless',
                '--invisible',
                '--norestore',
                '--convert-to',
                "csv:Text - txt - csv (StarCalc):{$libreOfficeOptions->getFilterOptions()}",
                '--outdir',
                $tmpDir,
                $path,
            ],
            env: [
                'HOME' => $tmpDir,
                'LANG' => $libreOfficeOptions->getLocale(),
                'LC_ALL' => $libreOfficeOptions->getLocale(),
            ],
            timeout: 60
        );
        $conversionProcess->disableOutput();

        $failed = false;
        try {
            $conversionProcess->run();
        } catch (ProcessTimedOutException|ProcessSignaledException) {
            // Treated like an unsuccessful exit
            $failed = true;
        }

        // Its name depends on the chosen sheet, e.g. "<file>-<sheet name>.csv", the directory is new so it's the only csv there
        $csvFiles = array_values(array_filter(
            (array) scandir($tmpDir),
            fn ($entry): bool => is_string($entry) && str_ends_with($entry, '.csv') && is_file($tmpDir . $entry)
        ));

        if ($failed || !$conversionProcess->isSuccessful() || count($csvFiles) !== 1 || !is_readable($tmpDir . $csvFiles[0])) {
            throw new SheetImportException("Can't read converted csv file.");
        }

        return $tmpDir . $csvFiles[0];
    }

    /**
     * @return Reader<array<string>>
     *
     * @throws SheetImportException
     * @throws InvalidArgument
     * @throws UnavailableStream
     */
    public function loadCsv(string $path = '', string $content = ''): Reader
    {
        if ($content !== '') {
            $csv = Reader::fromString($content);
        } elseif ($path !== '') {
            $csv = Reader::from($path);
        } else {
            throw new SheetImportException('One of the two arguments must be valid.');
        }

        $csv->setEscape('');

        $delimiters = Info::getDelimiterStats($csv, [',', ';'], 16);
        if ($delimiters !== []) {
            $delimiter = array_search(max($delimiters), $delimiters, true);
            if (!empty($delimiter)) {
                $csv->setDelimiter((string) $delimiter);
            }
        }

        return $csv;
    }
}
