<?php

use GNfsys\SheetImport\Common;
use GNfsys\SheetImport\Exceptions\SheetImportException;
use GNfsys\SheetImport\Importable;
use GNfsys\SheetImport\LibreOfficeOptions;
use GNfsys\SheetImport\OnEachRow;
use GNfsys\SheetImport\SheetImport;
use GNfsys\SheetImport\WithHeadingRow;
use GNfsys\SheetImport\WithTempDirectory;
use League\Csv\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
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

            public function onRow(array $row, int $offset): void
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

            public function onRow(array $row, int $offset): void
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

            public function onRow(array $row, int $offset): void
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

            public function onRow(array $row, int $offset): void
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

            public function __construct()
            {
                //
            }

            private function headingRow(): int
            {
                return 2;
            }

            public function onRow(array $row, int $offset): void
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

        // Starts from first row, second one is header, resumes from third
        $this->assertSame(1, $importable->offsets[0]);
        $this->assertSame(3, $importable->offsets[1]);

        unlink($tmpPath);
    }
}
