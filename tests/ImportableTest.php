<?php

use Bakame\TabularData\HtmlTable\ParserError;
use GNfsys\SheetImport\Common;
use GNfsys\SheetImport\Exceptions\HtmlConversionException;
use GNfsys\SheetImport\Exceptions\SheetImportException;
use GNfsys\SheetImport\Importable;
use GNfsys\SheetImport\LibreOfficeOptions;
use GNfsys\SheetImport\OnEachRow;
use GNfsys\SheetImport\SheetImport;
use GNfsys\SheetImport\WithHeadingRow;
use GNfsys\SheetImport\WithStartRow;
use GNfsys\SheetImport\WithTempDirectory;
use League\Csv\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversTrait(Importable::class)]
#[CoversClass(SheetImport::class)]
#[UsesClass(Common::class)]
#[UsesClass(LibreOfficeOptions::class)]
class ImportableTest extends TestCase
{
    private const string HTML_TABLE = '<!DOCTYPE html><html><body><table>'
        . '<thead><tr><th>col1</th><th>col2</th></tr></thead>'
        . '<tbody><tr><td>aa</td><td>bb</td></tr><tr><td>cc</td><td>dd</td></tr></tbody>'
        . '</table></body></html>';

    /** Base directory for the temporary files, removed after each test */
    private string $baseDir;

    /** @var list<string> */
    private array $inputFiles = [];

    protected function setUp(): void
    {
        $baseDir = sys_get_temp_dir() . '/sheet_import_test_' . bin2hex(random_bytes(4));
        mkdir($baseDir);

        // Temp paths are resolved, the system temp directory may be a symlink
        $realPath = realpath($baseDir);
        $this->assertIsString($realPath);
        $this->baseDir = $realPath;
    }

    protected function tearDown(): void
    {
        SheetImport::setTempDirectory(null);

        foreach ($this->inputFiles as $file) {
            unlink($file);
        }

        self::removeDirectory($this->baseDir);
    }

    private static function removeDirectory(string $dir): void
    {
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            $path = "$dir/$entry";
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function createInputFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'importable_input_');
        $this->assertIsString($path);
        file_put_contents($path, $content);

        $this->inputFiles[] = $path;

        return $path;
    }

    /**
     * @return list<string>
     */
    private function listBaseDir(): array
    {
        $entries = scandir($this->baseDir);
        $this->assertIsArray($entries);

        return array_values(array_diff($entries, ['.', '..']));
    }

    /**
     * @throws Throwable
     */
    public function testDefaultTempDirectoryIsSystemTempDirectory(): void
    {
        SheetImport::setTempDirectory('/somewhere');
        SheetImport::setTempDirectory(null);

        $this->assertSame(sys_get_temp_dir(), SheetImport::getTempDirectory());
    }

    /**
     * @throws Throwable
     */
    public function testConvertToCsvUsesGlobalTempDirectory(): void
    {
        // Missing parent directories are created
        SheetImport::setTempDirectory($this->baseDir . '/nested/dir/');

        $importable = new class () {
            use Importable;
        };

        $csvPath = $importable->convertToCsv($this->createInputFile(self::HTML_TABLE));

        $this->assertStringStartsWith($this->baseDir . '/nested/dir/phpship', $csvPath);
        $this->assertFileExists($csvPath);

        $importable->cleanupTempFiles();

        $this->assertDirectoryDoesNotExist(dirname($csvPath));
    }

    /**
     * @throws Throwable
     */
    public function testWithTempDirectoryOverridesGlobalTempDirectory(): void
    {
        SheetImport::setTempDirectory($this->baseDir . '/global');

        $importable = new class ($this->baseDir . '/override') implements WithTempDirectory {
            use Importable;

            public function __construct(private string $dir)
            {
                //
            }

            public function tempDirectory(): string
            {
                return $this->dir;
            }
        };

        $csvPath = $importable->convertToCsv($this->createInputFile(self::HTML_TABLE));

        $this->assertStringStartsWith($this->baseDir . '/override/phpship', $csvPath);
        $this->assertDirectoryDoesNotExist($this->baseDir . '/global');

        $importable->cleanupTempFiles();
    }

    /**
     * @throws Throwable
     */
    public function testEmptyTempDirectoryIsRejected(): void
    {
        SheetImport::setTempDirectory('');

        $importable = new class () {
            use Importable;
        };

        $this->expectException(SheetImportException::class);
        $this->expectExceptionMessage('Temporary directory must not be empty.');

        $importable->convertToCsv($this->createInputFile(self::HTML_TABLE));
    }

    /**
     * @throws Throwable
     */
    public function testImportRemovesTempDirectory(): void
    {
        SheetImport::setTempDirectory($this->baseDir);

        $importable = new class ($this->listBaseDir(...)) implements OnEachRow {
            use Importable;

            /** @var array<array<mixed>> */
            public array $captured = [];

            /** @var list<string> */
            public array $tempDirsDuringImport = [];

            /**
             * @param Closure(): list<string> $listBaseDir
             */
            public function __construct(private Closure $listBaseDir)
            {
                //
            }

            public function onRow(array $row, int $offset, int $index): void
            {
                $this->captured[] = $row;
                $this->tempDirsDuringImport = ($this->listBaseDir)();
            }
        };

        $importable->import($this->createInputFile(self::HTML_TABLE));

        $this->assertNotEmpty($importable->captured);
        $this->assertCount(1, $importable->tempDirsDuringImport);
        $this->assertSame([], $this->listBaseDir());
    }

    /**
     * @throws Throwable
     */
    public function testImportRemovesTempDirectoryWhenRowHandlerThrows(): void
    {
        SheetImport::setTempDirectory($this->baseDir);

        $importable = new class () implements OnEachRow {
            use Importable;

            public function onRow(array $row, int $offset, int $index): void
            {
                throw new RuntimeException('Row handler failed');
            }
        };

        try {
            $importable->import($this->createInputFile(self::HTML_TABLE));
            $this->fail('Expected the row handler exception to propagate');
        } catch (RuntimeException $exception) {
            $this->assertSame('Row handler failed', $exception->getMessage());
        }

        $this->assertSame([], $this->listBaseDir());
    }

    /**
     * @throws Throwable
     */
    public function testFailedConversionRemovesTempDirectory(): void
    {
        SheetImport::setTempDirectory($this->baseDir);

        $importable = new class () {
            use Importable;
        };

        // Not plain text nor html, so it goes through LibreOffice
        $zipFile = $this->createInputFile("PK\x03\x04" . str_repeat("\0", 64));

        try {
            $importable->convertToCsv($zipFile, new LibreOfficeOptions(executable: '/nonexistent/libreoffice'));
            $this->fail('Expected the conversion to fail');
        } catch (SheetImportException $exception) {
            $this->assertSame("Can't read converted csv file.", $exception->getMessage());
        }

        $this->assertSame([], $this->listBaseDir());
    }

    /**
     * @throws Throwable
     */
    public function testSignaledConversionIsAFailedConversion(): void
    {
        SheetImport::setTempDirectory($this->baseDir);

        $importable = new class () {
            use Importable;
        };

        // Stands in for a crashing LibreOffice
        $crashingBinary = $this->createInputFile("#!/bin/sh\nkill -9 \$\$\n");
        chmod($crashingBinary, 0700);

        $zipFile = $this->createInputFile("PK\x03\x04" . str_repeat("\0", 64));

        try {
            $importable->convertToCsv($zipFile, new LibreOfficeOptions(executable: $crashingBinary));
            $this->fail('Expected the conversion to fail');
        } catch (SheetImportException $exception) {
            $this->assertSame("Can't read converted csv file.", $exception->getMessage());
        }

        $this->assertSame([], $this->listBaseDir());
    }

    /**
     * @throws Throwable
     */
    public function testImportWithCsvWithoutHeader(): void
    {
        $data = [
            ['col1', 'col2', 'col3'],
            ['aa', 'bb', 'cc'],
            ['cc', 'bb', 'aa'],
        ];

        $importable = new class () implements OnEachRow {
            use Importable;

            /** @var array<array<mixed>> */
            public array $captured = [];

            /** @var array<int> */
            public array $offsets = [];

            public function onRow(array $row, int $offset, int $index): void
            {
                $this->captured[] = $row;
                $this->offsets[] = $offset;
            }
        };

        // Create a temporary file to run a fake import
        $tmpPath = tempnam(sys_get_temp_dir(), 'importable_test_');

        $writer = Writer::from($tmpPath, 'w+');
        $writer->insertAll($data);

        $importable->import($tmpPath);

        $this->assertCount(count($data), $importable->captured);
        $this->assertSame($data, $importable->captured);

        // Starts from first row
        $this->assertSame(1, $importable->offsets[0]);

        unlink($tmpPath);
    }

    /**
     * @throws Throwable
     */
    public function testImportWithCsvWithHeader(): void
    {
        $header = ['col1', 'col2', 'col3'];

        $rows = [
            ['aa', 'bb', 'cc'],
            ['cc', 'bb', 'aa'],
        ];

        $data = [
            $header,
            ['aa', 'bb', 'cc'],
            ['cc', 'bb', 'aa'],
        ];

        $expected = [];

        foreach ($rows as $rowNum => $row) {
            foreach ($row as $index => $cellValue) {
                $expected[$rowNum][$header[$index]] = $cellValue;
            }
        }

        $importable = new class () implements OnEachRow, WithHeadingRow {
            use Importable;

            /** @var array<array<mixed>> */
            public array $captured = [];

            /** @var array<int> */
            public array $offsets = [];

            public function __construct()
            {
                //
            }

            public function onRow(array $row, int $offset, int $index): void
            {
                $this->captured[] = $row;
                $this->offsets[] = $offset;
            }
        };

        // Create a temporary file to run a fake import
        $tmpPath = tempnam(sys_get_temp_dir(), 'importable_test_');

        $writer = Writer::from($tmpPath, 'w+');
        $writer->insertAll($data);

        $importable->import($tmpPath);

        $this->assertCount(count($rows), $importable->captured);
        $this->assertSame($expected, $importable->captured);

        // Starts from second row, first one is header
        $this->assertSame(2, $importable->offsets[0]);

        unlink($tmpPath);
    }

    /**
     * @throws Throwable
     */
    public function testImportWithCsvWithCustomHeader(): void
    {
        $header = ['aa', 'bb', 'cc'];

        $rows = [
            ['col1', 'col2', 'col3'],
            ['cc', 'bb', 'aa'],
        ];

        $data = [
            ['col1', 'col2', 'col3'],
            $header,
            ['cc', 'bb', 'aa'],
        ];

        $expected = [];

        foreach ($rows as $rowNum => $row) {
            foreach ($row as $index => $cellValue) {
                $expected[$rowNum][$header[$index]] = $cellValue;
            }
        }

        $importable = new class () implements OnEachRow, WithHeadingRow {
            use Importable;

            /** @var array<array<mixed>> */
            public array $captured = [];

            /** @var array<int> */
            public array $offsets = [];

            /** @var array<int> */
            public array $indexes = [];

            public function __construct()
            {
                //
            }

            private function headingRow(): int
            {
                return 2;
            }

            public function onRow(array $row, int $offset, int $index): void
            {
                $this->captured[] = $row;
                $this->offsets[] = $offset;
                $this->indexes[] = $index;
            }
        };

        // Create a temporary file to run a fake import
        $tmpPath = tempnam(sys_get_temp_dir(), 'importable_test_');

        $writer = Writer::from($tmpPath, 'w+');
        $writer->insertAll($data);

        $importable->import($tmpPath);

        $this->assertCount(count($rows), $importable->captured);
        $this->assertSame($expected, $importable->captured);

        // Starts from first row, second one is header, resumes from third
        $this->assertSame(1, $importable->offsets[0]);
        $this->assertSame(3, $importable->offsets[1]);

        // The index doesn't count the header
        $this->assertSame([0, 1], $importable->indexes);

        unlink($tmpPath);
    }

    /**
     * Heading row on the third row, rows before it are imported too
     *
     * @return iterable<string, array{int, list<int>, list<string>}>
     */
    public static function startRowProvider(): iterable
    {
        yield 'first row' => [1, [1, 2, 4, 5], ['r1', 'r2', 'r4', 'r5']];
        yield 'before the heading row' => [2, [2, 4, 5], ['r2', 'r4', 'r5']];
        yield 'on the heading row' => [3, [4, 5], ['r4', 'r5']];
        yield 'right after the heading row' => [4, [4, 5], ['r4', 'r5']];
        yield 'after the heading row' => [5, [5], ['r5']];
        yield 'past the last row' => [6, [], []];
    }

    /**
     * @param list<int>    $expectedOffsets
     * @param list<string> $expectedValues
     *
     * @throws Throwable
     */
    #[DataProvider('startRowProvider')]
    public function testImportWithStartRow(int $startRow, array $expectedOffsets, array $expectedValues): void
    {
        $importable = new class ($startRow) implements OnEachRow, WithHeadingRow, WithStartRow {
            use Importable;

            /** @var list<mixed> */
            public array $values = [];

            /** @var list<int> */
            public array $offsets = [];

            /** @var list<int> */
            public array $indexes = [];

            public function __construct(private int $start)
            {
                //
            }

            public function headingRow(): int
            {
                return 3;
            }

            public function startRow(): int
            {
                return $this->start;
            }

            public function onRow(array $row, int $offset, int $index): void
            {
                $this->values[] = $row['col1'];
                $this->offsets[] = $offset;
                $this->indexes[] = $index;
            }
        };

        $importable->import($this->createInputFile("r1,a\nr2,b\ncol1,col2\nr4,c\nr5,d\n"));

        $this->assertSame($expectedValues, $importable->values);
        $this->assertSame($expectedOffsets, $importable->offsets);
        $this->assertSame(array_keys($expectedOffsets), $importable->indexes);
    }

    /**
     * @throws Throwable
     */
    public function testImportKeepsRowNumbersAcrossEmptyRows(): void
    {
        $importable = new class () implements OnEachRow, WithHeadingRow {
            use Importable;

            /** @var list<int> */
            public array $offsets = [];

            /** @var list<int> */
            public array $indexes = [];

            public function onRow(array $row, int $offset, int $index): void
            {
                $this->offsets[] = $offset;
                $this->indexes[] = $index;
            }
        };

        $importable->import($this->createInputFile("col1,col2\naa,bb\n\ncc,dd\n"));

        $this->assertSame([2, 4], $importable->offsets);
        $this->assertSame([0, 1], $importable->indexes);
    }

    /**
     * @throws Throwable
     */
    public function testImportWithDuplicateHeaderCollidingWithRealColumn(): void
    {
        $importable = new class () implements OnEachRow, WithHeadingRow {
            use Importable;

            /** @var array<array<mixed>> */
            public array $captured = [];

            public function onRow(array $row, int $offset, int $index): void
            {
                $this->captured[] = $row;
            }
        };

        $importable->import($this->createInputFile("a,a_1,a\n1,2,3\n"));

        $this->assertSame([['a' => '1', 'a_1' => '2', 'a_2' => '3']], $importable->captured);
    }

    /**
     * @throws Throwable
     */
    public function testHtmlConversionKeepsHeaderRow(): void
    {
        SheetImport::setTempDirectory($this->baseDir);

        $importable = new class () {
            use Importable;
        };

        $csvPath = $importable->convertToCsv($this->createInputFile(self::HTML_TABLE));

        $this->assertSame("col1,col2\naa,bb\ncc,dd\n", file_get_contents($csvPath));

        $importable->cleanupTempFiles();
    }

    /**
     * @throws Throwable
     */
    public function testImportHtmlWithHeadingRow(): void
    {
        SheetImport::setTempDirectory($this->baseDir);

        $importable = new class () implements OnEachRow, WithHeadingRow {
            use Importable;

            /** @var array<array<mixed>> */
            public array $captured = [];

            /** @var list<int> */
            public array $offsets = [];

            public function onRow(array $row, int $offset, int $index): void
            {
                $this->captured[] = $row;
                $this->offsets[] = $offset;
            }
        };

        $importable->import($this->createInputFile(self::HTML_TABLE));

        $this->assertSame([['col1' => 'aa', 'col2' => 'bb'], ['col1' => 'cc', 'col2' => 'dd']], $importable->captured);
        $this->assertSame([2, 3], $importable->offsets);
    }

    /**
     * @throws Throwable
     */
    public function testImportHtmlWithTwoHeadRowsAndDuplicateNames(): void
    {
        SheetImport::setTempDirectory($this->baseDir);

        $importable = new class () implements OnEachRow, WithHeadingRow {
            use Importable;

            /** @var array<array<mixed>> */
            public array $captured = [];

            /** @var list<int> */
            public array $offsets = [];

            public function headingRow(): int
            {
                return 2;
            }

            public function onRow(array $row, int $offset, int $index): void
            {
                $this->captured[] = $row;
                $this->offsets[] = $offset;
            }
        };

        // A title spanning both columns, above repeated column names
        $html = '<!DOCTYPE html><html><body><table>'
            . '<thead><tr><th colspan="2">Report</th></tr><tr><th>Name</th><th>Name</th></tr></thead>'
            . '<tbody><tr><td>Ann</td><td>Bea</td></tr></tbody>'
            . '</table></body></html>';

        $importable->import($this->createInputFile($html));

        // The title row is above the heading row, so it's imported with the same names
        $this->assertSame(
            [['name' => 'Report', 'name_1' => 'Report'], ['name' => 'Ann', 'name_1' => 'Bea']],
            $importable->captured
        );
        $this->assertSame([1, 3], $importable->offsets);
    }

    /**
     * @throws Throwable
     */
    public function testHtmlWithoutTableIsAFailedConversion(): void
    {
        SheetImport::setTempDirectory($this->baseDir);

        $importable = new class () {
            use Importable;
        };

        $htmlFile = $this->createInputFile('<!DOCTYPE html><html><body><p>No table here</p></body></html>');

        try {
            $importable->convertToCsv($htmlFile);
            $this->fail('Expected the conversion to fail');
        } catch (HtmlConversionException $exception) {
            $this->assertSame('Failed to convert Html file', $exception->getMessage());
            $this->assertInstanceOf(ParserError::class, $exception->getPrevious());
        }

        $this->assertSame([], $this->listBaseDir());
    }

    /**
     * @throws Throwable
     */
    public function testImportFromFileAndFromStringReadTheSameRows(): void
    {
        $content = "col1,col2\naa,bb\ncc,dd\n";

        $newImportable = fn () => new class () implements OnEachRow, WithHeadingRow {
            use Importable;

            /** @var list<string> */
            public array $captured = [];

            public function onRow(array $row, int $offset, int $index): void
            {
                $this->captured[] = "{$offset}/{$index} " . json_encode($row);
            }
        };

        $fromFile = $newImportable();
        $fromFile->importFromFile($this->createInputFile($content));

        $fromString = $newImportable();
        $fromString->importFromString($content);

        $this->assertSame(['2/0 {"col1":"aa","col2":"bb"}', '3/1 {"col1":"cc","col2":"dd"}'], $fromFile->captured);
        $this->assertSame($fromFile->captured, $fromString->captured);
    }

    /**
     * @throws Throwable
     */
    public function testImportFromFileRejectsMissingFile(): void
    {
        $importable = new class () {
            use Importable;
        };

        $this->expectException(SheetImportException::class);
        $this->expectExceptionMessage('"' . $this->baseDir . '/missing.xlsx" does not exist.');

        $importable->importFromFile($this->baseDir . '/missing.xlsx');
    }

    /**
     * @throws Throwable
     */
    public function testImportFromFileRejectsDirectory(): void
    {
        $importable = new class () {
            use Importable;
        };

        $this->expectException(SheetImportException::class);
        $this->expectExceptionMessage('"' . $this->baseDir . '" is not a file.');

        $importable->importFromFile($this->baseDir);
    }

    /**
     * @throws Throwable
     */
    public function testImportFromFileRejectsUnreadableFile(): void
    {
        $importable = new class () {
            use Importable;
        };

        $path = $this->createInputFile("col1,col2\n");
        chmod($path, 0000);

        if (is_readable($path)) {
            $this->markTestSkipped('The file is still readable, e.g. when running as root');
        }

        $this->expectException(SheetImportException::class);
        $this->expectExceptionMessage('File "' . $path . '" is not readable.');

        $importable->importFromFile($path);
    }

    /**
     * @throws Throwable
     */
    public function testImportFromStringRejectsEmptyContent(): void
    {
        $importable = new class () {
            use Importable;
        };

        $this->expectException(SheetImportException::class);
        $this->expectExceptionMessage('Content must not be empty.');

        $importable->importFromString('');
    }

    /**
     * @throws Throwable
     */
    public function testImportFromStringNeverReadsAFile(): void
    {
        $importable = new class () implements OnEachRow {
            use Importable;

            /** @var array<array<mixed>> */
            public array $captured = [];

            public function onRow(array $row, int $offset, int $index): void
            {
                $this->captured[] = $row;
            }
        };

        // Content that happens to be the path of an existing file
        $path = $this->createInputFile("col1,col2\naa,bb\n");

        $importable->importFromString($path);

        $this->assertSame([[$path]], $importable->captured);
    }

    /**
     * @throws Throwable
     */
    public function testSettingsAreValidatedBeforeConversion(): void
    {
        SheetImport::setTempDirectory($this->baseDir);

        $importable = new class () implements OnEachRow, WithStartRow {
            use Importable;

            public function startRow(): int
            {
                return 0;
            }

            public function onRow(array $row, int $offset, int $index): void
            {
                //
            }
        };

        // Converting it would throw an HtmlConversionException instead
        $htmlFile = $this->createInputFile('<!DOCTYPE html><html><body><p>No table here</p></body></html>');

        try {
            $importable->importFromFile($htmlFile);
            $this->fail('Expected the start row to be rejected');
        } catch (SheetImportException $exception) {
            $this->assertNotInstanceOf(HtmlConversionException::class, $exception);
            $this->assertSame('Row offset must be greater or equal to "0".', $exception->getMessage());
        }

        $this->assertSame([], $this->listBaseDir());
    }

    /**
     * @throws Throwable
     */
    public function testImportLeavesManualConversionsToTheCaller(): void
    {
        SheetImport::setTempDirectory($this->baseDir);

        $importable = new class ($this->createInputFile(self::HTML_TABLE)) implements OnEachRow {
            use Importable;

            public ?string $csvFromRow = null;

            public function __construct(private string $htmlFile)
            {
                //
            }

            public function onRow(array $row, int $offset, int $index): void
            {
                $this->csvFromRow ??= $this->convertToCsv($this->htmlFile);
            }
        };

        $csvBefore = $importable->convertToCsv($this->createInputFile(self::HTML_TABLE));

        $importable->importFromFile($this->createInputFile(self::HTML_TABLE));

        // Both manual conversions survive, before and during the import, only the import's own directory is gone
        $this->assertFileExists($csvBefore);
        $this->assertIsString($importable->csvFromRow);
        $this->assertFileExists($importable->csvFromRow);
        $this->assertCount(2, $this->listBaseDir());

        $importable->cleanupTempFiles();

        $this->assertSame([], $this->listBaseDir());
    }
}
