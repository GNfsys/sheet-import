<?php

use GNfsys\SheetImport\Common;
use GNfsys\SheetImport\Importable;
use GNfsys\SheetImport\LibreOfficeOptions;
use GNfsys\SheetImport\OnEachRow;
use GNfsys\SheetImport\WithHeadingRow;
use League\Csv\Writer;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversTrait(Importable::class)]
#[UsesClass(Common::class)]
#[UsesClass(LibreOfficeOptions::class)]
class ImportableTest extends TestCase
{
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
