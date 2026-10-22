<?php

use GNfsys\SheetImport\Common;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Common::class)]
class CommonTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function duplicatesProvider(): iterable
    {
        yield 'no duplicates' => [['a', 'b', 'c'], ['a', 'b', 'c']];
        yield 'same name repeated' => [['a', 'a', 'a'], ['a', 'a_1', 'a_2']];
        yield 'empty names' => [['', '', ''], ['', '_1', '_2']];

        // A generated name never takes the name of a real column
        yield 'real column before the duplicate' => [['a', 'a_1', 'a'], ['a', 'a_1', 'a_2']];
        yield 'real column after the duplicate' => [['a', 'a', 'a_1'], ['a', 'a_2', 'a_1']];
        yield 'real column first' => [['a_1', 'a', 'a'], ['a_1', 'a', 'a_2']];
        yield 'several real columns' => [['a', 'a', 'a_1', 'a_2', 'a'], ['a', 'a_3', 'a_1', 'a_2', 'a_4']];
        yield 'real column duplicated too' => [['a', 'a_1', 'a', 'a_1'], ['a', 'a_1', 'a_2', 'a_1_1']];
        yield 'numeric names' => [['1', '1', '1_1'], ['1', '1_2', '1_1']];
    }

    /**
     * @param list<string> $header
     * @param list<string> $expected
     */
    #[DataProvider('duplicatesProvider')]
    public function testHandleDuplicates(array $header, array $expected): void
    {
        $this->assertSame($expected, Common::handleDuplicates($header));
    }

    public function testPrepareHeaderRenamesNamesThatBecomeEqual(): void
    {
        $this->assertSame(['citta', 'citta_1'], Common::prepareHeader(['Città', 'Citta']));
    }

    public function testHeaderWithKeysThatAreNotAListBecomesAList(): void
    {
        $header = [2 => 'Name', 5 => 'name', 'x' => 'City'];

        $this->assertSame([2 => 'name', 5 => 'name', 'x' => 'city'], Common::slugifyHeader($header));
        $this->assertSame(['Name', 'name', 'City'], Common::handleDuplicates($header));
        $this->assertSame(['name', 'name_1', 'city'], Common::prepareHeader($header));
    }
}
