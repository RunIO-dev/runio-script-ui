<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Runio\ScriptUi\csv;

enum CsvTestStatus: string
{
    case Paid = 'paid';
}

enum CsvTestSize
{
    case Large;
}

/**
 * Rows as CSV text: what download() sends when it is given rows.
 */
final class CsvTest extends TestCase
{
    private const BOM = "\xEF\xBB\xBF";

    private const ORDERS = [
        ['id' => 1001, 'customer' => 'Ava', 'total' => 40.5],
        ['id' => 1002, 'customer' => 'Ben', 'total' => 9],
    ];

    /** The file without the mark at its start, line by line. */
    private static function lines(string $csv): array
    {
        self::assertStringStartsWith(self::BOM, $csv);
        $lines = explode("\r\n", substr($csv, 3));
        self::assertSame('', array_pop($lines), 'the file ends with a line break');

        return $lines;
    }

    public function testTheFirstLineNamesTheColumnsAndEveryRowIsALine(): void
    {
        self::assertSame(
            ['id,customer,total', '1001,Ava,40.5', '1002,Ben,9'],
            self::lines(csv(self::ORDERS)),
        );
    }

    public function testTheFileStartsWithTheMarkThatTellsExcelItIsUtf8(): void
    {
        self::assertSame(self::BOM . "name\r\nعمر\r\n", csv([['name' => 'عمر']]));
        self::assertSame("name\r\nعمر\r\n", csv([['name' => 'عمر']], bom: false));
    }

    public function testColumnsCanBeChosenAndRenamed(): void
    {
        self::assertSame(
            ['total,Order', '40.5,1001', '9,1002'],
            self::lines(csv(self::ORDERS, columns: ['total', 'id' => 'Order'])),
        );
    }

    public function testAColumnThatOnlySomeRowsHaveIsStillAColumn(): void
    {
        self::assertSame(
            ['id,note', '1,', '2,late'],
            self::lines(csv([['id' => 1], ['id' => 2, 'note' => 'late']])),
        );
    }

    public function testValuesAreWrittenSoThatAProgramCanReadThemBack(): void
    {
        $rows = [[
            'nothing' => null,
            'yes' => true,
            'no' => false,
            'whole' => 12,
            'part' => 0.1 + 0.2,
            'long' => 1234567890.123456,
            'when' => new \DateTimeImmutable('2026-03-01 14:30:05'),
            'status' => CsvTestStatus::Paid,
            'size' => CsvTestSize::Large,
            'list' => ['a' => 1],
        ]];

        self::assertSame(
            [
                'nothing,yes,no,whole,part,long,when,status,size,list',
                // PHP puts a cell with a space in quotes. Every reader of CSV takes them off again.
                ',true,false,12,0.30000000000000004,1234567890.123456,"2026-03-01 14:30:05",paid,Large,"{""a"":1}"',
            ],
            self::lines(csv($rows)),
        );
    }

    public function testTextWithSeparatorsQuotesOrLineBreaksIsQuoted(): void
    {
        $csv = csv([['note' => 'a,b', 'said' => 'she said "hi"', 'lines' => "one\ntwo"]]);

        self::assertSame(
            self::BOM . "note,said,lines\r\n\"a,b\",\"she said \"\"hi\"\"\",\"one\ntwo\"\r\n",
            $csv,
        );
    }

    public function testABackslashNeedsNoSpecialCare(): void
    {
        // PHP's old default would leave the quote after a backslash undoubled.
        self::assertSame(['path', '"C:\""x"'], self::lines(csv([['path' => 'C:\"x']])));
    }

    public function testAnotherSeparatorCanBeUsed(): void
    {
        self::assertSame(
            ['id;customer;total', '1001;Ava;40.5', '1002;Ben;9'],
            self::lines(csv(self::ORDERS, separator: ';')),
        );
        self::assertSame(['a;b', '"1;5";2'], self::lines(csv([['a' => '1;5', 'b' => 2]], separator: ';')));
        self::assertSame(["id\tcustomer\ttotal", "1001\tAva\t40.5", "1002\tBen\t9"], self::lines(csv(self::ORDERS, separator: "\t")));
        self::assertSame(['id|customer|total', '1001|Ava|40.5', '1002|Ben|9'], self::lines(csv(self::ORDERS, separator: '|')));
        self::assertSame(['a:b', '"1:5":2'], self::lines(csv([['a' => '1:5', 'b' => 2]], separator: ':')));
    }

    /** @return iterable<string,array{string}> */
    public static function badSeparators(): iterable
    {
        yield 'empty' => [''];
        yield 'two characters' => [';;'];
        yield 'a quote' => ['"'];
        yield 'a line break' => ["\n"];
        yield 'not one byte' => ['؛'];
        yield 'a letter' => ['a'];
        yield 'a digit' => ['1'];
        yield 'what starts a formula' => ['='];
        yield 'a space' => [' '];
    }

    #[DataProvider('badSeparators')]
    public function testASeparatorIsOneSimpleCharacter(string $separator): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('separator');

        csv(self::ORDERS, separator: $separator);
    }

    /** @return iterable<string,array{string}> */
    public static function formulas(): iterable
    {
        yield 'equals' => ['=1+1'];
        yield 'a command' => ['=cmd|\' /C calc\'!A0'];
        yield 'plus' => ['+1+1'];
        yield 'minus' => ['-1+1'];
        yield 'at' => ['@SUM(1+1)'];
        yield 'tab' => ["\t=1+1"];
        yield 'carriage return' => ["\r=1+1"];
        yield 'a phone number' => ['+49 170 1234567'];
        yield 'a note that starts with a dash' => ['- call back on Monday'];
        yield 'spaces first' => ['  =1+1'];
        yield 'a line break first' => ["\n=1+1"];
        yield 'a form feed first' => ["\x0C=1+1"];
        yield 'a space that does not break first' => ["\u{00A0}=1+1"];
        yield 'a mark nobody sees first' => ["\u{FEFF}=1+1"];
        yield 'a wide equals sign' => ['＝1+1'];
        yield 'a wide at sign' => ['＠SUM(1+1)'];
    }

    #[DataProvider('formulas')]
    public function testACellASpreadsheetWouldRunAsAFormulaIsDefused(string $cell): void
    {
        $handle = fopen('php://memory', 'w+');
        self::assertIsResource($handle);
        fwrite($handle, substr(csv([['note' => $cell]]), 3));
        rewind($handle);
        fgetcsv($handle, null, ',', '"', '');
        $read = fgetcsv($handle, null, ',', '"', '');
        fclose($handle);

        self::assertSame(["'" . $cell], $read);
    }

    public function testOrdinaryTextIsLeftAlone(): void
    {
        self::assertSame(
            ['a,b,c', 'x=1,a-b,me@example.com'],
            self::lines(csv([['a' => 'x=1', 'b' => 'a-b', 'c' => 'me@example.com']])),
        );
    }

    public function testNumbersAreNotFormulas(): void
    {
        self::assertSame(
            ['a,b,c,d,e', '-5,-0.5,-12.50,+3,-1e3'],
            self::lines(csv([['a' => -5, 'b' => -0.5, 'c' => '-12.50', 'd' => '+3', 'e' => '-1e3']])),
        );
    }

    public function testNumbersThatAreNoNumbersAreWrittenOut(): void
    {
        // PHP 8.5 warns when "not a number" is turned into text. The file names it without a warning.
        self::assertSame(['a,b,c', 'NAN,INF,-INF'], self::lines(csv([['a' => NAN, 'b' => INF, 'c' => -INF]])));
    }

    public function testTheNamesOfTheColumnsAreDefusedToo(): void
    {
        self::assertSame(["'=1+1", '1'], self::lines(csv([['=1+1' => 1]])));
        self::assertSame(["'@x", '1'], self::lines(csv([['id' => 1]], columns: ['id' => '@x'])));
    }

    public function testFormulasCanBeLeftAsTheyAre(): void
    {
        self::assertSame(['sum', '=A1+A2'], self::lines(csv([['sum' => '=A1+A2']], allowFormulas: true)));
    }

    public function testRowsCanBeObjectsAndCanComeFromAGenerator(): void
    {
        $rows = (static function (): \Generator {
            yield (object) ['id' => 1, 'name' => 'Ava'];
            yield new \ArrayObject(['id' => 2, 'name' => 'Ben']);
        })();

        self::assertSame(['id,name', '1,Ava', '2,Ben'], self::lines(csv($rows)));
    }

    public function testWithNamedColumnsEachRowIsWrittenAsItComes(): void
    {
        // Nothing is kept: when the third row is asked for, the first two are already in the file.
        $made = 0;
        $rows = (static function () use (&$made): \Generator {
            foreach ([1, 2, 3] as $id) {
                $made++;
                yield ['id' => $id, 'name' => 'row ' . $id, 'left out' => 'x'];
            }
        })();

        self::assertSame(
            ['Number,name', '1,"row 1"', '2,"row 2"', '3,"row 3"'],
            self::lines(csv($rows, columns: ['id' => 'Number', 'name'])),
        );
        self::assertSame(3, $made);
    }

    public function testAColumnThatIsANumberCanStillBeRenamed(): void
    {
        // PHP turns the key '2024' into a number. The first row says that it is a column and not a position.
        self::assertSame(
            ['"Year 2024",2025', '5,7'],
            self::lines(csv([[2024 => 5, 2025 => 7]], columns: [2024 => 'Year 2024', 2025])),
        );
    }

    public function testNoRowsGiveAnEmptyFileOrOnlyTheNamesOfTheChosenColumns(): void
    {
        self::assertSame(self::BOM, csv([]));
        self::assertSame(['id,Total'], self::lines(csv([], columns: ['id', 'total' => 'Total'])));
    }

    public function testBrokenTextCannotBreakTheFile(): void
    {
        $lines = self::lines(csv([['name' => "caf\xE9"]]));

        self::assertSame('name', $lines[0]);
        self::assertTrue(mb_check_encoding($lines[1], 'UTF-8'));
    }
}
