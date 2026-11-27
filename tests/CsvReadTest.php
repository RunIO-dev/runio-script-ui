<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use PHPUnit\Framework\TestCase;
use Runio\ScriptUi\Csv;

use function Runio\ScriptUi\csv;

/**
 * CSV text as rows: what Upload::rows() gives a tool.
 *
 * The files people upload were written by a spreadsheet, and each spreadsheet
 * writes its own kind of CSV.
 */
final class CsvReadTest extends TestCase
{
    private const BOM = "\xEF\xBB\xBF";

    public function testTheFirstLineNamesTheColumns(): void
    {
        $rows = Csv::read("email,items,total\nava@example.com,Notebook,24.50\nomar@example.com,Monitor arm,89\n");

        self::assertSame([
            ['email' => 'ava@example.com', 'items' => 'Notebook', 'total' => '24.50'],
            ['email' => 'omar@example.com', 'items' => 'Monitor arm', 'total' => '89'],
        ], $rows);
    }

    public function testEveryCellIsText(): void
    {
        // What a number or a date means is for the tool to say. "007" stays "007".
        $rows = Csv::read("code,amount,when\n007,1.50,2026-03-01\n");

        self::assertSame([['code' => '007', 'amount' => '1.50', 'when' => '2026-03-01']], $rows);
    }

    public function testTheMarkThatExcelWritesIsNotPartOfTheFirstName(): void
    {
        $rows = Csv::read(self::BOM . "email,total\na@example.com,1\n");

        self::assertSame(['email', 'total'], array_keys($rows[0]));
    }

    public function testWindowsLineEndingsAndAMissingLastOneAreFine(): void
    {
        self::assertSame(
            [['a' => '1', 'b' => '2'], ['a' => '3', 'b' => '4']],
            Csv::read("a,b\r\n1,2\r\n3,4"),
        );
    }

    public function testLineEndingsOfAnOldMacAreFine(): void
    {
        self::assertSame([['a' => '1', 'b' => '2'], ['a' => '3', 'b' => '4']], Csv::read("a,b\r1,2\r3,4\r"));
    }

    public function testACellInQuotesCanHoldTheSeparatorAQuoteAndALineBreak(): void
    {
        $rows = Csv::read("name,note\n\"Haddad, Omar\",\"said \"\"hello\"\"\nand left\"\n");

        self::assertSame([['name' => 'Haddad, Omar', 'note' => "said \"hello\"\nand left"]], $rows);
    }

    public function testABackslashIsALetterLikeAnyOther(): void
    {
        // PHP used to read a backslash before a quote as an escape. No spreadsheet writes it that way.
        $rows = Csv::read("path,next\n\"C:\\temp\\\",x\n");

        self::assertSame([['path' => 'C:\\temp\\', 'next' => 'x']], $rows);
    }

    // ------------------------------------------------------------ separators

    public function testASemicolonIsFoundOut(): void
    {
        // What Excel writes where people write 1,5 for one and a half.
        $rows = Csv::read("Name;Betrag\nMüller;1,50\nSchmidt;12,00\n");

        self::assertSame([['Name' => 'Müller', 'Betrag' => '1,50'], ['Name' => 'Schmidt', 'Betrag' => '12,00']], $rows);
    }

    public function testATabIsFoundOut(): void
    {
        self::assertSame([['a' => '1', 'b' => 'x, y']], Csv::read("a\tb\n1\tx, y\n"));
    }

    public function testABarIsFoundOut(): void
    {
        self::assertSame([['a' => '1', 'b' => '2']], Csv::read("a|b\n1|2\n"));
    }

    public function testTheSeparatorThatCutsEveryLineTheSameWayWins(): void
    {
        // The first line holds as many commas as semicolons. The lines below settle it.
        $rows = Csv::read("Name, first;Amount, net\nHaddad, Omar;1,50\nMorgan, Ava;2,00\n");

        self::assertSame(['Name, first', 'Amount, net'], array_keys($rows[0]));
        self::assertSame('Haddad, Omar', $rows[0]['Name, first']);
    }

    public function testAFileWrittenByHandWithSpacesAfterItsCommasIsStillCutAtThem(): void
    {
        $rows = Csv::read("name, city, total\nAva, London, 12\nOmar, Amman, 7\n");

        self::assertSame(['name', 'city', 'total'], array_keys($rows[0]));
        self::assertSame('Ava', $rows[0]['name']);
    }

    public function testLinesOfDifferentLengthDoNotHideTheSeparator(): void
    {
        // Free text with commas, and a last line that ends early.
        $rows = Csv::read("name;note;total\nAva;likes red, blue, green;12\nOmar;none;7\nLina\n");

        self::assertSame(['name', 'note', 'total'], array_keys($rows[0]));
        self::assertSame('likes red, blue, green', $rows[0]['note']);
        self::assertSame(['name' => 'Lina', 'note' => '', 'total' => ''], $rows[2]);
    }

    public function testOneColumnIsOneColumn(): void
    {
        self::assertSame([['email' => 'a@example.com'], ['email' => 'b@example.com']], Csv::read("email\na@example.com\nb@example.com\n"));
    }

    public function testTheSeparatorCanBeNamed(): void
    {
        // Found out, this file would have one column with a comma in it.
        self::assertSame([['a,b' => '1,2']], Csv::read("a,b\n1,2\n", separator: ';'));
        self::assertSame([['a' => '1', 'b' => '2']], Csv::read("a:b\n1:2\n", separator: ':'));
    }

    public function testASeparatorHasToBeOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('separator');

        Csv::read("a,b\n1,2\n", separator: 'x');
    }

    public function testTheLineThatTellsExcelTheSeparatorIsReadAndLeftOut(): void
    {
        $rows = Csv::read("sep=;\nname;total\nAva;1,5\n");

        self::assertSame([['name' => 'Ava', 'total' => '1,5']], $rows);
    }

    // ------------------------------------------------------------- encodings

    public function testAFileFromAnOlderExcelIsReadAsWindowsText(): void
    {
        // Not UTF-8: "Müller" and "José" as a Western Windows writes them.
        $rows = Csv::read("Name;Ort\nM\xFCller;K\xF6ln\nJos\xE9;M\xE1laga\n");

        self::assertSame([['Name' => 'Müller', 'Ort' => 'Köln'], ['Name' => 'José', 'Ort' => 'Málaga']], $rows);
    }

    public function testTheEncodingCanBeNamed(): void
    {
        // Greek, as ISO-8859-7 writes it.
        $rows = Csv::read("name\n\xC1\xE8\xDE\xED\xE1\n", encoding: 'ISO-8859-7');

        self::assertSame([['name' => 'Αθήνα']], $rows);
    }

    public function testAnArabicWindowsFileIsReadWhenItsEncodingIsNamed(): void
    {
        if (!function_exists('iconv') || @iconv('Windows-1256', 'UTF-8', "\xE3") === false) {
            self::markTestSkipped('This PHP cannot read Windows-1256.');
        }

        $rows = Csv::read("\xC7\xE1\xC7\xD3\xE3;\xC7\xE1\xE3\xC8\xE1\xDB\n\xDA\xE3\xD1;12\n", encoding: 'Windows-1256');

        self::assertSame([['الاسم' => 'عمر', 'المبلغ' => '12']], $rows);
    }

    public function testAnEncodingThatPhpDoesNotKnowIsAMistake(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('encoding');

        Csv::read("a\n1\n", encoding: 'Klingon-8');
    }

    public function testUnicodeTextFromExcelIsRead(): void
    {
        // "Unicode Text" in Excel: UTF-16 with its mark, cells apart by tabs.
        $text = "\xFF\xFE" . mb_convert_encoding("الاسم\tالمبلغ\r\nعمر\t12\r\n", 'UTF-16LE', 'UTF-8');

        self::assertSame([['الاسم' => 'عمر', 'المبلغ' => '12']], Csv::read($text));

        $big = "\xFE\xFF" . mb_convert_encoding("a\tb\n1\t2\n", 'UTF-16BE', 'UTF-8');
        self::assertSame([['a' => '1', 'b' => '2']], Csv::read($big));
    }

    public function testArabicAndAccentsAtTheStartOfACellStayWhole(): void
    {
        // Older PHP lost the first letter of such a cell under some system settings.
        $rows = Csv::read("الاسم,Ünvan\nعمر,Ömer\nأحمد,Çelik\n");

        self::assertSame([['الاسم' => 'عمر', 'Ünvan' => 'Ömer'], ['الاسم' => 'أحمد', 'Ünvan' => 'Çelik']], $rows);
    }

    // ------------------------------------------------------- untidy files

    public function testEmptyLinesAndLinesOfSeparatorsAreLeftOut(): void
    {
        $rows = Csv::read("\n\na,b\n\n1,2\n,\n   \n3,4\n,,\n\n");

        self::assertSame([['a' => '1', 'b' => '2'], ['a' => '3', 'b' => '4']], $rows);
    }

    public function testAShortLineGetsEmptyCells(): void
    {
        self::assertSame([['a' => '1', 'b' => '', 'c' => ''], ['a' => '1', 'b' => '2', 'c' => '3']], Csv::read("a,b,c\n1\n1,2,3\n"));
    }

    public function testACellBeyondTheNamedColumnsIsKeptUnderItsNumber(): void
    {
        // Empty cells at the end of a line are what Excel writes for a sheet with a wide title row.
        $rows = Csv::read("a,b\n1,2,,\n3,4,extra\n");

        self::assertSame([['a' => '1', 'b' => '2'], ['a' => '3', 'b' => '4', 'column 3' => 'extra']], $rows);
    }

    public function testNamesAreTrimmedAndNeverEmptyOrTwice(): void
    {
        $rows = Csv::read(" email , ,email,Email\n1,2,3,4\n");

        self::assertSame([['email' => '1', 'column 2' => '2', 'email (2)' => '3', 'Email' => '4']], $rows);
    }

    public function testCellsKeepTheirSpaces(): void
    {
        // Only names are trimmed. What a cell holds is not ours to change.
        self::assertSame([['a' => ' x ', 'b' => 'y']], Csv::read("a,b\n x ,y\n"));
    }

    public function testNamesWithoutRowsGiveNoRows(): void
    {
        self::assertSame([], Csv::read("a,b,c\n"));
        self::assertSame([], Csv::read(''));
        self::assertSame([], Csv::read(self::BOM));
        self::assertSame([], Csv::read("\n\n"));
    }

    public function testAFileThatIsNotTextHasNoRows(): void
    {
        // A picture with ".csv" at the end of its name. There is nothing in it to read.
        $png = "\x89PNG\r\n\x1A\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x02\0\0\0";

        self::assertSame([], Csv::read($png));
        self::assertSame([], Csv::read("a,b\n1,\0\n"));
    }

    public function testAFormulaIsJustText(): void
    {
        // Nothing here runs a cell. Shown in a table it is text, and csv() defuses it on the way out.
        $rows = Csv::read("name,total\n=HYPERLINK(\"http://evil\"),+1\n");

        self::assertSame([['name' => '=HYPERLINK("http://evil")', 'total' => '+1']], $rows);
    }

    public function testWhatCsvWritesIsReadBack(): void
    {
        $orders = [
            ['id' => '1001', 'customer' => 'Haddad, Omar', 'note' => "two\nlines", 'total' => '40.5'],
            ['id' => '1002', 'customer' => 'أحمد', 'note' => 'said "hi"', 'total' => '12'],
        ];

        self::assertSame($orders, Csv::read(csv($orders)));
        self::assertSame($orders, Csv::read(csv($orders, separator: ';')));
        self::assertSame($orders, Csv::read(csv($orders, separator: "\t", bom: false)));
    }

    public function testALongFileIsReadWhole(): void
    {
        $lines = ["id;name"];
        for ($n = 1; $n <= 20000; $n++) {
            $lines[] = $n . ';row ' . $n;
        }

        $rows = Csv::read(implode("\r\n", $lines));

        self::assertCount(20000, $rows);
        self::assertSame(['id' => '20000', 'name' => 'row 20000'], $rows[19999]);
    }
}
