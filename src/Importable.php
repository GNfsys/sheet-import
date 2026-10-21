<?php

declare(strict_types=1);

namespace GNfsys\SheetImport;

use Bakame\TabularData\HtmlTable\Parser;
use Bakame\TabularData\HtmlTable\Section;
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
    private int $headerOffset = 0;

    private int $startRow = 0;

    /** @var list<string> */
    private array $tmpDirs = [];

    /**
     * @throws UnavailableStream
     * @throws SyntaxError
     * @throws RandomException
     * @throws CannotInsertRecord
     * @throws Exception
     */
    private function convertHtmlToCsv(string $path, string $dest): void
    {
        $table = Parser::new()
            ->tableHeaderPosition(Section::Thead)
            ->parseFile($path);

        $writer = Writer::from($dest, 'w+');

        $writer->insertAll($table);
    }

    /**
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
        if ($this instanceof WithStartRow) {
            $this->startRow = $this->startRow() - 1;
            if ($this instanceof WithHeadingRow) {
                $this->startRow--;
            }

            // dd($this->start_row);

            if ($this->startRow < 0) {
                throw new SheetImportException('Row offset must be greater or equal to "0".');
            }
        }

        if ($this instanceof WithHeadingRow && is_callable([$this, 'headingRow'])) {
            $this->headerOffset = ((int) $this->headingRow()) - 1;

            if ($this->headerOffset < 0) {
                throw new SheetImportException("Heading row offset must be greater than or equal to \"0\". It's \"$this->headerOffset\".");
            }

            // if ($this->header_offset < $this->start_row) {
            //    throw new SheetImportException('Heading row offset must be greater than or equal to the start offset.');
            // }
        }

        try {
            if (file_exists($file)) {
                $csv_path = self::convertToCsv($file);
                $reader = self::loadCsv(path: $csv_path);
            } else {
                $reader = self::loadCsv(content: $file);
            }

            if ($this instanceof WithHeadingRow) {
                $reader->setHeaderOffset($this->headerOffset);
            }

            $header = $reader->getHeader();
            if ($this instanceof WithHeadingRow) {
                $header = $headerFormat ? $headerFormat($header) : Common::prepareHeader($header);
            }

            if (!is_array($header)) {
                throw new SheetImportException('Header is expected to be an array');
            }

            // Use $header values as array keys
            $records = new Statement()->offset($this->startRow)->process($reader, $header);

            if ($this instanceof ToIterator) {
                $this->handleIterator($records);
            } elseif ($this instanceof OnEachRow) {
                // From zero based to one based
                $offset = $this->startRow;
                $offset++;

                // The heading row is removed when used
                if ($this instanceof WithHeadingRow && $this->headerOffset === 0) {
                    $offset++;
                }

                foreach ($records as $record) {
                    $this->onRow($record, $offset);

                    if ($offset === $this->headerOffset) {
                        $offset++;
                    }
                    $offset++;
                }
            }
        } finally {
            $this->cleanupTempFiles();
        }
    }

    /**
     * Delete the temporary directories created by convertToCsv(), import() calls this automatically
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
     * The returned csv lives in a temporary directory, call cleanupTempFiles() when done with it
     *
     * @throws SheetImportException
     * @throws RandomException
     * @throws HtmlConversionException
     * @throws Throwable
     */
    public function convertToCsv(
        string $path,
        LibreOfficeOptions $libreOfficeOptions = new LibreOfficeOptions(),
    ): string {
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
            return $path;
        }

        $tmpDir = $this->createTempDirectory();

        $csvFilename = pathinfo($path, PATHINFO_FILENAME) . '.csv';
        $csvFilepath = $tmpDir . $csvFilename;

        try {
            // Fake xls handling
            if ($mimeType === 'text/html') {
                try {
                    $this->convertHtmlToCsv($path, $csvFilepath);
                } catch (Exception $exception) {
                    throw new HtmlConversionException('Failed to convert Html file', previous: $exception);
                }
            } else {
                $this->convertWithLibreOffice($path, $tmpDir, $csvFilepath, $libreOfficeOptions);
            }
        } catch (Throwable $throwable) {
            self::removeDirectory($tmpDir);

            throw $throwable;
        }

        $this->tmpDirs[] = $tmpDir;

        return $csvFilepath;
    }

    /**
     * @throws SheetImportException
     */
    private function convertWithLibreOffice(
        string $path,
        string $tmpDir,
        string $csvFilepath,
        LibreOfficeOptions $libreOfficeOptions,
    ): void {
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

        if ($failed || !$conversionProcess->isSuccessful() || !is_readable($csvFilepath)) {
            throw new SheetImportException("Can't read converted csv file.");
        }
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
