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
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

trait Importable
{
    private int $headerOffset = 0;

    private int $startRow = 0;

    /** @var list<string> */
    private array $tmpFiles = [];

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

        // Cleanup temp files
        foreach ($this->tmpFiles as $path) {
            if (is_writable($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @throws SheetImportException
     * @throws RandomException
     * @throws HtmlConversionException
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

        $tmpDir = sys_get_temp_dir();
        if (!str_ends_with($tmpDir, '/')) {
            $tmpDir .= '/';
        }

        $tmpDir .= 'phpship' . bin2hex(random_bytes(6)) . '/';

        if (!mkdir($tmpDir, 0770, true)) {
            throw new SheetImportException("Could not create temporary directory \"{$tmpDir}\".");
        }

        $csvFilename = basename($path) . '.csv';
        $csvFilepath = $tmpDir . $csvFilename;

        // Fake xls handling
        if ($mimeType === 'text/html') {
            try {
                $this->convertHtmlToCsv($path, $csvFilepath);
            } catch (Exception $exception) {
                throw new HtmlConversionException('Failed to convert Html file', previous: $exception);
            }

            return $csvFilepath;
        }

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

        $conversionProcess->start();

        $timedOut = false;
        while ($conversionProcess->isRunning()) {
            try {
                $conversionProcess->checkTimeout();
            } catch (ProcessTimedOutException) {
                $timedOut = true;

                break;
            }

            usleep(200_000);
        }

        if ($timedOut || !$conversionProcess->isSuccessful() || !is_readable($csvFilepath)) {
            throw new SheetImportException("Can't read converted csv file.");
        }

        $this->tmpFiles[] = $csvFilepath;

        return $csvFilepath;
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
