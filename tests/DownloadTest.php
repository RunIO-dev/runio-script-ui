<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Runio\ScriptUi\Config;
use Runio\ScriptUi\Http\Request;
use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\columns;
use function Runio\ScriptUi\csv;
use function Runio\ScriptUi\download;
use function Runio\ScriptUi\note;
use function Runio\ScriptUi\table;
use function Runio\ScriptUi\text;

/**
 * download(): a button that hands the user a file.
 */
final class DownloadTest extends ToolTestCase
{
    private const BOM = "\xEF\xBB\xBF";

    private const ORDERS = [
        ['id' => 1001, 'customer' => 'Ava', 'total' => 40.5],
        ['id' => 1002, 'customer' => 'Ben', 'total' => 9],
    ];

    public function testAPageViewShowsTheButtonAndSendsThePage(): void
    {
        $response = $this->get(function (): void {
            download('Download CSV', self::ORDERS, 'orders.csv');
        });

        self::assertSame(200, $response->status);
        self::assertStringStartsWith('text/html', (string) $response->header('Content-Type'));
        self::assertNull($response->header('Content-Disposition'));
        self::assertSame(
            ['Download CSV'],
            $this->texts($response, '//button[@type="submit" and @name="_click" and @value="download-csv" and @id="b-download-csv"]'),
        );
    }

    public function testAClickSendsTheFileInsteadOfThePage(): void
    {
        $response = $this->post(function (): void {
            note('The page is not sent.');
            download('Download CSV', self::ORDERS, 'orders.csv');
        }, ['_click' => 'download-csv']);

        self::assertSame(200, $response->status);
        self::assertSame(self::BOM . "id,customer,total\r\n1001,Ava,40.5\r\n1002,Ben,9\r\n", $response->body);
        self::assertSame('text/csv; charset=utf-8', $response->header('Content-Type'));
        self::assertSame('attachment; filename="orders.csv"', $response->header('Content-Disposition'));
    }

    public function testThePageScriptGetsTheFileTheSameWay(): void
    {
        // The script of the page asks for content. A file is the answer all the same,
        // and one header tells the script what to save it as.
        $response = $this->post(function (): void {
            download('Download CSV', self::ORDERS, 'الطلبات 2026.csv');
        }, ['_click' => 'download-csv'], ['X-Script-Ui' => 'fragment']);

        self::assertSame(200, $response->status);
        self::assertStringStartsWith(self::BOM . "id,customer,total\r\n", $response->body);
        self::assertNull($response->header('X-Script-Ui-Fragment'));
        self::assertSame('الطلبات 2026.csv', rawurldecode((string) $response->header('X-Script-Ui-Download')));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9%._~-]+$/', (string) $response->header('X-Script-Ui-Download'));
    }

    public function testTheFileCanDoNothingInTheBrowser(): void
    {
        $response = $this->post(function (): void {
            download('Download', '<script>alert(1)</script>', 'page.html');
        }, ['_click' => 'download']);

        // Saved and never shown: not as a page, not inside a frame, not guessed to be something else.
        self::assertSame('application/octet-stream', $response->header('Content-Type'));
        self::assertStringStartsWith('attachment;', (string) $response->header('Content-Disposition'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame("default-src 'none'; frame-ancestors 'none'; sandbox", $response->header('Content-Security-Policy'));
        self::assertSame('DENY', $response->header('X-Frame-Options'));
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertNull($response->header('X-Script-Ui-Fragment'));
    }

    public function testTextIsSentAsItIs(): void
    {
        $response = $this->post(function (): void {
            download('Notes', "line one\nline two", 'notes.txt');
        }, ['_click' => 'notes']);

        self::assertSame("line one\nline two", $response->body);
        self::assertSame('text/plain; charset=utf-8', $response->header('Content-Type'));
    }

    public function testRowsBecomeJsonWhenTheNameEndsInJson(): void
    {
        $response = $this->post(function (): void {
            download('Export', self::ORDERS, 'orders.json');
        }, ['_click' => 'export']);

        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame(self::ORDERS, json_decode($response->body, true, 8, JSON_THROW_ON_ERROR));
        self::assertStringStartsWith('[', $response->body, 'no mark at the start: JSON does not allow one');
    }

    public function testJsonThatCannotBeWrittenFailsInsteadOfLosingValues(): void
    {
        $response = $this->post(function (): void {
            download('Export', [['id' => 1, 'ratio' => NAN]], 'orders.json');
        }, ['_click' => 'export']);

        self::assertSame(500, $response->status);
        self::assertNull($response->header('Content-Disposition'));
    }

    public function testRowsNeedANameThatSaysCsvOrJson(): void
    {
        // Rows in a file called .xlsx would be CSV with a wrong name. It shows on the first page view.
        $response = $this->get(function (): void {
            download('Export', self::ORDERS, 'orders.xlsx');
        });

        self::assertSame(500, $response->status);
        self::assertStringContainsString('.csv', $response->body);

        $response = $this->post(function (): void {
            download('Export', static fn (): array => self::ORDERS, 'orders.xlsx');
        }, ['_click' => 'export']);

        self::assertSame(500, $response->status);
        self::assertNull($response->header('Content-Disposition'));
    }

    public function testRowsBecomeTabSeparatedWhenTheNameEndsInTsv(): void
    {
        $response = $this->post(function (): void {
            download('Export', self::ORDERS, 'orders.tsv');
        }, ['_click' => 'export']);

        self::assertSame('text/tab-separated-values; charset=utf-8', $response->header('Content-Type'));
        self::assertStringStartsWith(self::BOM . "id\tcustomer\ttotal\r\n", $response->body);
    }

    public function testTheContentIsOnlyBuiltWhenTheFileIsAskedFor(): void
    {
        $built = 0;
        $tool = function () use (&$built): void {
            button('Other');
            download('Export', function () use (&$built): string {
                $built++;

                return csv(self::ORDERS, separator: ';');
            }, 'orders.csv');
        };

        $this->get($tool);
        $this->post($tool, ['_click' => 'other']);
        self::assertSame(0, $built);

        $response = $this->post($tool, ['_click' => 'export']);
        self::assertSame(1, $built);
        self::assertStringStartsWith(self::BOM . "id;customer;total\r\n", $response->body);
    }

    public function testAFunctionMayReturnRowsToo(): void
    {
        $response = $this->post(function (): void {
            download('Export', static fn (): array => self::ORDERS, 'orders.csv');
        }, ['_click' => 'export']);

        self::assertStringStartsWith(self::BOM . "id,customer,total\r\n", $response->body);
    }

    public function testTheRunEndsAtTheDownload(): void
    {
        $after = false;
        $this->post(function () use (&$after): void {
            download('Export', self::ORDERS, 'orders.csv');
            $after = true;
        }, ['_click' => 'export']);

        self::assertFalse($after);
    }

    public function testInputsBelowTheButtonKeepWhatTheUserEntered(): void
    {
        // The run ends at the download, so the inputs below it are never reached.
        // What the form sent for them must not be forgotten.
        $tool = function (): void {
            text('Above');
            download('Export', self::ORDERS, 'orders.csv');
            text('Below');
        };

        $this->post($tool, ['above' => 'one', 'below' => 'two', '_click' => 'export']);
        $response = $this->get($tool);

        self::assertSame('one', $this->attr($response, '//input[@name="above"]', 'value'));
        self::assertSame('two', $this->attr($response, '//input[@name="below"]', 'value'));
    }

    public function testADownloadIsWrittenToTheLog(): void
    {
        $this->post(function (): void {
            text('Customer');
            download('Export customers', self::ORDERS, 'customers.csv');
        }, ['customer' => 'ava', '_click' => 'export-customers']);

        self::assertCount(1, $this->logged);
        self::assertSame('Export customers', $this->logged[0]['action']);
        self::assertSame('export-customers', $this->logged[0]['key']);
        self::assertSame('tester', $this->logged[0]['user']);
        self::assertSame(['customer' => 'ava'], $this->logged[0]['inputs']);
        self::assertNull($this->logged[0]['error']);
        self::assertFalse($this->logged[0]['confirmed']);
        self::assertSame(
            ['file' => 'customers.csv', 'bytes' => strlen(self::BOM . "id,customer,total\r\n1001,Ava,40.5\r\n1002,Ben,9\r\n")],
            $this->logged[0]['download'],
        );
    }

    public function testWithoutALogEntryThereIsNoFile(): void
    {
        // Who took which data out is exactly what a log is for. When the log cannot
        // be written, the file stays where it is.
        $this->captureErrorLog();
        $this->config = new Config(
            auth: static fn (): string => 'tester',
            log: static fn (array $entry): never => throw new \RuntimeException('disk full'),
            debug: true,
        );

        $response = $this->post(function (): void {
            download('Export', self::ORDERS, 'orders.csv');
        }, ['_click' => 'export']);

        self::assertSame(500, $response->status);
        self::assertNull($response->header('Content-Disposition'));
        self::assertNull($response->header('X-Script-Ui-Download'));
        self::assertStringNotContainsString('1001', $response->body);
        self::assertStringContainsString('could not be written to the log', $response->body);
    }

    public function testALogThatAnswersFalseCountsAsNotWritten(): void
    {
        // file_put_contents() and many loggers do not throw. They answer false.
        $this->captureErrorLog();
        $this->config = new Config(
            auth: static fn (): string => 'tester',
            log: static fn (array $entry): bool => false,
            debug: true,
        );

        $response = $this->post(function (): void {
            download('Export', self::ORDERS, 'orders.csv');
        }, ['_click' => 'export']);

        self::assertSame(500, $response->status);
        self::assertNull($response->header('Content-Disposition'));
        self::assertStringNotContainsString('1001', $response->body);
    }

    public function testALogThatAnswersNothingIsFine(): void
    {
        $this->config = new Config(
            auth: static fn (): string => 'tester',
            log: static fn (array $entry): ?int => null,
            debug: true,
        );

        $response = $this->post(function (): void {
            download('Export', self::ORDERS, 'orders.csv');
        }, ['_click' => 'export']);

        self::assertSame('attachment; filename="orders.csv"', $response->header('Content-Disposition'));
    }

    public function testWithoutALogSettingTheFileIsSent(): void
    {
        $this->config = new Config(auth: static fn (): string => 'tester', debug: true);

        $response = $this->post(function (): void {
            download('Export', self::ORDERS, 'orders.csv');
        }, ['_click' => 'export']);

        self::assertSame('attachment; filename="orders.csv"', $response->header('Content-Disposition'));
    }

    public function testAPageViewNeverStartsADownload(): void
    {
        $built = 0;
        $tool = function () use (&$built): void {
            download('Export', function () use (&$built): array {
                $built++;

                return self::ORDERS;
            }, 'orders.csv');
        };

        $response = $this->get($tool, [], ['_click' => 'export']);

        self::assertSame(0, $built);
        self::assertStringStartsWith('text/html', (string) $response->header('Content-Type'));
        self::assertSame([], $this->logged);
    }

    public function testAFormFromSomewhereElseNeverStartsADownload(): void
    {
        $built = 0;
        $tool = function () use (&$built): void {
            download('Export', function () use (&$built): array {
                $built++;

                return self::ORDERS;
            }, 'orders.csv');
        };

        $this->token();
        $response = $this->kernel()->handle(
            new Request('POST', '/tool', [], ['_token' => 'guess', '_click' => 'export']),
            'tool',
            $tool,
        );

        self::assertSame(419, $response->status);
        self::assertSame(0, $built);

        // The right token, but the browser says another site sent the form.
        $response = $this->post($tool, ['_click' => 'export'], ['Sec-Fetch-Site' => 'cross-site']);

        self::assertSame(419, $response->status);
        self::assertSame(0, $built);
    }

    public function testWhenBuildingTheFileFailsThePageSaysSo(): void
    {
        $response = $this->post(function (): void {
            note('Still here');
            download('Export', static fn (): never => throw new \RuntimeException('database is away'), 'orders.csv');
        }, ['_click' => 'export']);

        self::assertSame(500, $response->status);
        self::assertNull($response->header('Content-Disposition'));
        self::assertStringStartsWith('text/html', (string) $response->header('Content-Type'));
        self::assertStringContainsString('database is away', $response->body);
        self::assertSame('database is away', $this->logged[0]['error'] ?? null);
        self::assertSame(['file' => 'orders.csv', 'bytes' => null], $this->logged[0]['download'] ?? null);
    }

    public function testAFailedDownloadIsShownInPlaceByThePageScript(): void
    {
        $response = $this->post(function (): void {
            download('Export', static fn (): never => throw new \RuntimeException('database is away'), 'orders.csv');
        }, ['_click' => 'export'], ['X-Script-Ui' => 'fragment']);

        self::assertSame(500, $response->status);
        self::assertSame('1', $response->header('X-Script-Ui-Fragment'));
        self::assertNull($response->header('X-Script-Ui-Download'));
        self::assertStringContainsString('database is away', $response->body);
    }

    public function testAFailedDownloadDoesNotOverwriteWhatWasSaved(): void
    {
        $tool = function (): void {
            text('Above');
            download('Export', static fn (): never => throw new \RuntimeException('database is away'), 'orders.csv');
        };

        $this->post($tool, ['above' => 'saved']);
        $this->post($tool, ['above' => 'changed', '_click' => 'export']);

        self::assertSame('saved', $this->attr($this->get($tool), '//input[@name="above"]', 'value'));
    }

    public function testADownloadThatIsNoLongerOnThePageSendsThePage(): void
    {
        // The rows changed since the page was drawn, and the button with them.
        $response = $this->post(function (): void {
            note('No orders left.');
        }, ['_click' => 'export']);

        self::assertSame(200, $response->status);
        self::assertNull($response->header('Content-Disposition'));
        self::assertSame(['No orders left.'], $this->texts($response, '//p[contains(@class,"sui-note")]'));
        self::assertSame([], $this->logged);
    }

    public function testADownloadInsideAColumnSendsTheFileAndNothingElse(): void
    {
        $response = $this->post(function (): void {
            echo 'printed before';
            columns(
                static fn () => note('left'),
                static function (): void {
                    download('Export', 'only this', 'a.txt');
                },
            );
        }, ['_click' => 'export']);

        self::assertSame('only this', $response->body);
    }

    public function testATryBlockAroundTheDownloadDoesNotLoseTheFile(): void
    {
        // The run is ended by throwing. A tool that catches everything still hands out the file.
        $response = $this->post(function (): void {
            try {
                download('Export', 'only this', 'a.txt');
            } catch (\Throwable) {
                note('caught');
            }
        }, ['_click' => 'export']);

        self::assertSame('only this', $response->body);
        self::assertSame('attachment; filename="a.txt"', $response->header('Content-Disposition'));
    }

    public function testTheTicksOfATableSurviveADownload(): void
    {
        $tool = function (): void {
            $picked = table(self::ORDERS, selectable: true);
            download('Export ticked', static fn (): array => $picked, 'ticked.csv');
        };

        $box = (string) $this->attr($this->get($tool), '//table//tbody/tr[2]//input[@type="checkbox"]', 'value');
        $response = $this->post($tool, ['_sel' => ['table' => [$box]], '_click' => 'export-ticked']);

        self::assertSame(self::BOM . "id,customer,total\r\n1002,Ben,9\r\n", $response->body);
        self::assertSame(1, $this->hits($this->get($tool), '//table//tbody//input[@type="checkbox" and @checked]'));
    }

    public function testAnswersToAQuestionAreNotMistakenForTheDownload(): void
    {
        // "export:yes:..." is what a button with a question sends. A download has no question.
        $response = $this->post(function (): void {
            download('Export', self::ORDERS, 'orders.csv');
        }, ['_click' => 'export:yes:0123456789ab']);

        self::assertStringStartsWith('text/html', (string) $response->header('Content-Type'));
    }

    /** @return iterable<string,array{string, string}> */
    public static function names(): iterable
    {
        yield 'plain' => ['orders.csv', 'attachment; filename="orders.csv"'];
        yield 'spaces stay' => ['orders 2026.csv', 'attachment; filename="orders 2026.csv"'];
        yield 'no folders' => ['../../etc/passwd', 'attachment; filename="download..-..-etc-passwd"'];
        yield 'not hidden' => ['.htaccess', 'attachment; filename="download.htaccess"'];
        yield 'only an ending, when nothing was typed in front of it' => ['.csv', 'attachment; filename="download.csv"'];
        yield 'nothing left' => [' .. ', 'attachment; filename="download"'];
        yield 'empty' => ['', 'attachment; filename="download"'];
        // With the mark, "reportfdp.exe.csv" would be shown as "reportvsc.exe.pdf".
        yield 'no marks that turn the direction' => ["report\u{202E}fdp.exe.csv", 'attachment; filename="reportfdp.exe.csv"'];
        yield 'no marks nobody sees' => ["a\u{2066}b\u{2069}\u{200F}\u{FEFF}.csv", 'attachment; filename="ab.csv"'];
        yield 'no line separators' => ["a\u{2028}b\u{0085}c.csv", 'attachment; filename="a b c.csv"'];
        yield 'not longer than a file system takes' => [str_repeat('a', 400) . '.csv', 'attachment; filename="' . str_repeat('a', 146) . '.csv"'];
        yield 'no percent signs to decode' => ['100%25.csv', "attachment; filename=\"100_25.csv\"; filename*=UTF-8''100%2525.csv"];
        yield 'no backslashes' => ['C:\\temp\\a.csv', 'attachment; filename="C--temp-a.csv"'];
        yield 'no quotes' => ['q1 "final".csv', 'attachment; filename="q1 -final-.csv"'];
        yield 'no line breaks' => ["a\r\nSet-Cookie: x=1.csv", 'attachment; filename="a Set-Cookie- x=1.csv"'];
        yield 'another script' => ['الطلبات.csv', "attachment; filename=\"download.csv\"; filename*=UTF-8''%D8%A7%D9%84%D8%B7%D9%84%D8%A8%D8%A7%D8%AA.csv"];
        yield 'mixed' => ['Bestellungen März.csv', "attachment; filename=\"Bestellungen M_rz.csv\"; filename*=UTF-8''Bestellungen%20M%C3%A4rz.csv"];
    }

    #[DataProvider('names')]
    public function testTheNameOfTheFileIsMadeSafe(string $file, string $header): void
    {
        $response = $this->post(function () use ($file): void {
            download('Export', 'x', $file);
        }, ['_click' => 'export']);

        self::assertSame($header, $response->header('Content-Disposition'));
    }

    public function testANameAUserTypedCanNeverBreakThePage(): void
    {
        // The name is often made from an input. Whatever is in it, the page is drawn.
        foreach (['', '..', '///', "\r\n", "\u{202E}", str_repeat('.', 300)] as $typed) {
            $response = $this->get(function () use ($typed): void {
                download('Export', self::ORDERS, $typed . '.csv', key: 'export');
            });

            self::assertSame(200, $response->status, json_encode($typed) ?: '');
        }
    }

    /** @return iterable<string,array{string, string}> */
    public static function types(): iterable
    {
        yield ['report.pdf', 'application/pdf'];
        yield ['backup.zip', 'application/zip'];
        yield ['book.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        yield ['data.xml', 'application/xml'];
        yield ['DATA.CSV', 'text/csv; charset=utf-8'];
        yield ['archive.tar.gz', 'application/octet-stream'];
        yield ['noending', 'application/octet-stream'];
        // Never as something a browser would run.
        yield ['page.html', 'application/octet-stream'];
        yield ['drawing.svg', 'application/octet-stream'];
        yield ['code.js', 'application/octet-stream'];
    }

    #[DataProvider('types')]
    public function testTheTypeFollowsTheEndingOfTheName(string $file, string $type): void
    {
        $response = $this->post(function () use ($file): void {
            download('Export', 'x', $file);
        }, ['_click' => 'export']);

        self::assertSame($type, $response->header('Content-Type'));
    }

    public function testAnUnknownStyleIsRefused(): void
    {
        $response = $this->get(function (): void {
            download('Export', 'x', 'a.txt', style: 'huge');
        });

        self::assertSame(500, $response->status);
        self::assertStringContainsString('download() knows the styles', $response->body);
    }

    public function testTwoDownloadsWithTheSameLabelNeedTheirOwnKeys(): void
    {
        $tool = function (): void {
            download('Export', 'orders', 'orders.txt', key: 'orders');
            download('Export', 'customers', 'customers.txt', key: 'customers');
        };

        self::assertSame('orders', $this->post($tool, ['_click' => 'orders'])->body);
        self::assertSame('customers', $this->post($tool, ['_click' => 'customers'])->body);
    }

    public function testContentThatIsNeitherRowsNorTextIsRefused(): void
    {
        $response = $this->post(function (): void {
            download('Export', static fn (): int => 5, 'a.csv');
        }, ['_click' => 'export']);

        self::assertSame(500, $response->status);
        self::assertStringContainsString('rows or text', $response->body);
    }
}
