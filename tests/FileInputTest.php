<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Config;
use Runio\ScriptUi\Http\Request;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Session\ArraySession;
use Runio\ScriptUi\Tests\Support\ToolTestCase;
use Runio\ScriptUi\Upload;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\checkbox;
use function Runio\ScriptUi\columns;
use function Runio\ScriptUi\file_input;
use function Runio\ScriptUi\rerun;
use function Runio\ScriptUi\stop;
use function Runio\ScriptUi\success;
use function Runio\ScriptUi\table;
use function Runio\ScriptUi\text;
use function Runio\ScriptUi\textarea;
use function Runio\ScriptUi\title;

/**
 * A field that takes a file. The file stays on the server between the runs of
 * a tool, and the form only carries a handle for it.
 */
final class FileInputTest extends ToolTestCase
{
    private const CSV = "id,total\n1,40\n";

    private const GONE = 'A file that was here is gone. Please choose it again.';

    /** A tool with one file field, and what the script got from it. */
    private function tool(mixed &$file, mixed ...$options): \Closure
    {
        return function () use (&$file, $options): void {
            $file = file_input('Report', ...$options);
        };
    }

    /** @return list<string> the handles of the files a field shows */
    private function handles(Response $response, string $key = 'report'): array
    {
        return $this->texts($response, '//input[@name="_file[' . $key . '][]"]/@value');
    }

    /** @return list<string> the names of the files a page shows */
    private function shown(Response $response): array
    {
        return $this->texts($response, '//*[contains(@class,"sui-file-name")]');
    }

    /** @return list<string> what a page says went wrong with a file, as it is shown: without the word in front that is only read out */
    private function errors(Response $response): array
    {
        $shown = [];
        foreach ($this->xpath($response)->query('//p[contains(@class,"sui-field-error")]') ?: [] as $note) {
            $text = '';
            foreach ($note->childNodes as $child) {
                if (!$child instanceof \DOMElement || $child->getAttribute('class') !== 'sui-sr') {
                    $text .= $child->textContent;
                }
            }
            $shown[] = trim($text);
        }

        return $shown;
    }

    private function limitNote(Response $response): string
    {
        return $this->texts($response, '//p[contains(@class,"sui-file-limit")]')[0] ?? '';
    }

    // ------------------------------------------------------------------ basics

    public function testAFileFieldReturnsNothingUntilAFileIsThere(): void
    {
        $response = $this->get($this->tool($file));

        self::assertNull($file);
        self::assertSame('file', $this->attr($response, '//input[@id="f-report"]', 'type'));
        self::assertSame('report', $this->attr($response, '//input[@id="f-report"]', 'name'));
        self::assertNull($this->attr($response, '//input[@id="f-report"]', 'multiple'));
        self::assertSame(['Report'], $this->texts($response, '//label[@for="f-report"]'));
        self::assertSame([], $this->shown($response));
        self::assertSame([], $this->errors($response));
    }

    public function testAPageWithAFileFieldSendsItsFormInTheWayFilesNeed(): void
    {
        self::assertSame('multipart/form-data', $this->attr($this->get($this->tool($file)), '//form[@id="sui-form"]', 'enctype'));

        $plain = $this->get(static function (): void {
            text('Name');
        });
        self::assertNull($this->attr($plain, '//form[@id="sui-form"]', 'enctype'));
    }

    public function testAnUploadedFileIsReturned(): void
    {
        $response = $this->post($this->tool($file), files: ['report' => $this->arrived('orders.csv', self::CSV)]);

        self::assertInstanceOf(Upload::class, $file);
        self::assertSame('orders.csv', $file->name);
        self::assertSame(14, $file->size);
        self::assertSame(self::CSV, $file->contents());
        self::assertSame($this->uploadFolder, dirname($file->path));
        self::assertFileExists($file->path);

        // The page shows the file, and a way to take it away again.
        self::assertSame(['orders.csv'], $this->shown($response));
        self::assertSame(['14 B'], $this->texts($response, '//*[contains(@class,"sui-file-size")]'));
        $handle = $this->handles($response)[0];
        self::assertSame(['Remove'], $this->texts($response, '//button[@value="report:remove:' . $handle . '"]'));
        self::assertSame('Remove orders.csv', $this->attr($response, '//button[@value="report:remove:' . $handle . '"]', 'aria-label'));
        self::assertSame('f-report', $this->attr($response, '//button[@value="report:remove:' . $handle . '"]', 'data-sui-return'));
        self::assertSame([], $this->errors($response));
    }

    public function testTheFileIsStillThereOnTheNextRunAndAfterAReload(): void
    {
        $tool = $this->tool($file);
        $uploaded = $this->post($tool, files: ['report' => $this->arrived('orders.csv', self::CSV)]);
        $path = $file->path;

        // The form is sent again without a new file: another input changed, say.
        $file = null;
        $again = $this->post($tool, $this->form($uploaded));
        self::assertSame('orders.csv', $file?->name);
        self::assertSame($path, $file?->path);
        self::assertSame($this->handles($uploaded), $this->handles($again));

        // And the page is loaded again.
        $file = null;
        $reloaded = $this->get($tool);
        self::assertSame($path, $file?->path);
        self::assertSame(['orders.csv'], $this->shown($reloaded));
    }

    public function testWhatTheFormCarriesIsAHandleAndNeverAPath(): void
    {
        $response = $this->post($this->tool($file), files: ['report' => $this->arrived('orders.csv', self::CSV)]);

        $handle = $this->handles($response)[0];
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $handle);
        self::assertNotSame(basename($file->path), $handle);
        self::assertStringNotContainsString(basename($file->path), $response->body);
        self::assertStringNotContainsString((string) $this->uploadFolder, $response->body);
    }

    public function testANewFileTakesThePlaceOfTheOldOne(): void
    {
        $tool = $this->tool($file);
        $first = $this->post($tool, files: ['report' => $this->arrived('old.csv', 'old')]);
        $old = $file->path;

        $second = $this->post($tool, $this->form($first), files: ['report' => $this->arrived('new.csv', 'newer')]);

        self::assertSame('new.csv', $file->name);
        self::assertSame('newer', $file->contents());
        self::assertSame(['new.csv'], $this->shown($second));
        self::assertFileDoesNotExist($old);
        self::assertCount(1, $this->stored());
    }

    public function testRemoveTakesTheFileAway(): void
    {
        $tool = $this->tool($file);
        $uploaded = $this->post($tool, files: ['report' => $this->arrived('orders.csv', self::CSV)]);
        $handle = $this->handles($uploaded)[0];

        $removed = $this->post($tool, ['_click' => 'report:remove:' . $handle] + $this->form($uploaded));

        self::assertNull($file);
        self::assertSame([], $this->shown($removed));
        self::assertSame([], $this->handles($removed));
        self::assertSame([], $this->errors($removed));
        self::assertSame([], $this->stored());

        $this->get($tool);
        self::assertNull($file);
    }

    public function testNothingChosenIsNotAnError(): void
    {
        $response = $this->post($this->tool($file), files: ['report' => $this->arrived('', '', UPLOAD_ERR_NO_FILE)]);

        self::assertNull($file);
        self::assertSame([], $this->errors($response));
    }

    // ---------------------------------------------------------- several files

    public function testAFieldCanTakeSeveralFiles(): void
    {
        $tool = $this->tool($files, multiple: true);

        $empty = $this->get($tool);
        self::assertSame([], $files);
        self::assertSame('report[]', $this->attr($empty, '//input[@id="f-report"]', 'name'));
        self::assertNotNull($this->attr($empty, '//input[@id="f-report"]', 'multiple'));

        $two = $this->post($tool, $this->form($empty), files: ['report' => $this->arrivedTogether(['a.csv' => 'A', 'b.csv' => 'BB'])]);
        self::assertSame(['a.csv', 'b.csv'], array_map(static fn (Upload $upload): string => $upload->name, $files));
        self::assertSame([1, 2], array_map(static fn (Upload $upload): int => $upload->size, $files));
        self::assertSame(['a.csv', 'b.csv'], $this->shown($two));

        // More files are added to the ones that are there.
        $three = $this->post($tool, $this->form($two), files: ['report' => $this->arrivedTogether(['c.csv' => 'CCC'])]);
        self::assertSame(['a.csv', 'b.csv', 'c.csv'], $this->shown($three));
        self::assertCount(3, $files);

        // Each has its own button to take it away.
        $handles = $this->handles($three);
        $left = $this->post($tool, ['_click' => 'report:remove:' . $handles[1]] + $this->form($three));
        self::assertSame(['a.csv', 'c.csv'], $this->shown($left));
        self::assertSame(['A', 'CCC'], array_map(static fn (Upload $upload): string => $upload->contents(), $files));
        self::assertCount(2, $this->stored());
    }

    public function testAFieldHoldsNoMoreThanTwentyFiles(): void
    {
        $tool = $this->tool($files, multiple: true);
        $many = [];
        for ($n = 1; $n <= 25; $n++) {
            $many['file-' . $n . '.txt'] = (string) $n;
        }

        $response = $this->post($tool, files: ['report' => $this->arrivedTogether($many)]);

        self::assertCount(20, $files);
        self::assertSame('file-20.txt', $files[19]->name);
        self::assertSame(['No more than 20 files fit here.'], $this->errors($response));
        self::assertCount(20, $this->stored());

        // One more on top of twenty does not fit either.
        $again = $this->post($tool, $this->form($response), files: ['report' => $this->arrivedTogether(['one-more.txt' => 'x'])]);
        self::assertCount(20, $files);
        self::assertSame(['No more than 20 files fit here.'], $this->errors($again));
        self::assertCount(20, $this->stored());
    }

    public function testAFieldHoldsNoMoreFilesThanPhpTakesInOneRequest(): void
    {
        // max_file_uploads = 3: PHP drops the fourth file of a request without a word.
        $this->countLimit = 3;
        $tool = $this->tool($files, multiple: true);

        $empty = $this->get($tool);
        self::assertSame('3', $this->attr($empty, '//input[@id="f-report"]', 'data-sui-room'));
        self::assertSame('No more than 3 files fit here.', $this->attr($empty, '//input[@id="f-report"]', 'data-sui-too-many'));

        $two = $this->post($tool, $this->form($empty), files: ['report' => $this->arrivedTogether(['a.txt' => 'a', 'b.txt' => 'b'])]);
        self::assertSame('1', $this->attr($two, '//input[@id="f-report"]', 'data-sui-room'));

        $full = $this->post($tool, $this->form($two), files: ['report' => $this->arrivedTogether(['c.txt' => 'c', 'd.txt' => 'd'])]);
        self::assertSame(['a.txt', 'b.txt', 'c.txt'], $this->shown($full));
        self::assertSame(['No more than 3 files fit here.'], $this->errors($full));
        self::assertSame('0', $this->attr($full, '//input[@id="f-report"]', 'data-sui-room'));
    }

    public function testAFieldForOneFileTakesOne(): void
    {
        // A forged form sends two files for a field that takes one.
        $this->post($this->tool($file), files: ['report' => $this->arrivedTogether(['a.csv' => 'A', 'b.csv' => 'B'])]);

        self::assertSame('a.csv', $file?->name);
        self::assertCount(1, $this->stored());
    }

    // ------------------------------------------------------------------ types

    public function testOnlyTheNamedKindsOfFileAreTaken(): void
    {
        $tool = $this->tool($file, types: ['csv', 'xlsx']);

        $empty = $this->get($tool);
        self::assertSame('.csv,.xlsx', $this->attr($empty, '//input[@id="f-report"]', 'accept'));
        self::assertSame('Allowed: CSV, XLSX. Up to 1 MB.', $this->limitNote($empty));

        $refused = $this->post($tool, files: ['report' => $this->arrived('holiday.png', 'not a table')]);
        self::assertNull($file);
        self::assertSame(['"holiday.png" is not allowed here. Allowed: CSV, XLSX.'], $this->errors($refused));
        self::assertSame([], $this->stored());

        // Upper and lower case are the same.
        $this->post($tool, files: ['report' => $this->arrived('ORDERS.CSV', self::CSV)]);
        self::assertSame('ORDERS.CSV', $file?->name);
    }

    public function testOnlyTheLastEndingOfANameCounts(): void
    {
        $tool = $this->tool($file, types: ['csv']);

        $this->post($tool, files: ['report' => $this->arrived('orders.csv.exe', 'x')]);
        self::assertNull($file);

        $this->post($tool, files: ['report' => $this->arrived('csv', 'x')]);
        self::assertNull($file, 'a name without an ending has no kind');

        $this->post($tool, files: ['report' => $this->arrived('orders.exe.csv', 'x')]);
        self::assertSame('orders.exe.csv', $file?->name);
    }

    public function testTheKindsCanBeWrittenWithADotAndInAnyCase(): void
    {
        $response = $this->get($this->tool($file, types: ['.CSV', 'Tar.Gz']));

        self::assertSame('.csv,.gz', $this->attr($response, '//input[@id="f-report"]', 'accept'));
    }

    public function testKindsThatMakeNoSenseAreAMistakeInTheTool(): void
    {
        foreach ([[''], ['*'], ['image/png'], ['a b'], [5], []] as $types) {
            $response = $this->get($this->tool($file, types: $types));

            self::assertSame(500, $response->status, json_encode($types) . ' was taken');
            self::assertStringContainsString('file_input()', $response->body);
        }
    }

    // ------------------------------------------------------------------- size

    public function testAFileThatIsTooLargeIsRefused(): void
    {
        $tool = $this->tool($file, maxSize: 10);

        $empty = $this->get($tool);
        self::assertSame('Up to 10 B.', $this->limitNote($empty));
        // The page script checks the size before it sends anything.
        self::assertSame('10', $this->attr($empty, '//input[@id="f-report"]', 'data-sui-max'));
        self::assertSame('"{name}" is larger than 10 B.', $this->attr($empty, '//input[@id="f-report"]', 'data-sui-too-large'));

        $refused = $this->post($tool, files: ['report' => $this->arrived('big.csv', '12345678901')]);
        self::assertNull($file);
        self::assertSame(['"big.csv" is larger than 10 B.'], $this->errors($refused));
        self::assertSame([], $this->stored());

        $this->post($tool, files: ['report' => $this->arrived('fits.csv', '1234567890')]);
        self::assertSame('fits.csv', $file?->name);
    }

    public function testTheLimitOfPhpCountsWhenItIsSmaller(): void
    {
        $this->fileLimit = 2048;
        $tool = $this->tool($file, maxSize: 5 * 1024 * 1024);

        self::assertSame('Up to 2 KB.', $this->limitNote($this->get($tool)));

        // PHP itself refuses such a file and only says so.
        $refused = $this->post($tool, files: ['report' => $this->arrived('big.csv', '', UPLOAD_ERR_INI_SIZE)]);
        self::assertNull($file);
        self::assertSame(['"big.csv" is larger than 2 KB.'], $this->errors($refused));
    }

    public function testWithoutAnyLimitNoneIsNamed(): void
    {
        $this->fileLimit = 0;
        $this->bodyLimit = 0;
        $response = $this->get($this->tool($file));

        self::assertSame('', $this->limitNote($response));
        self::assertNull($this->attr($response, '//input[@id="f-report"]', 'data-sui-max'));
    }

    public function testSizesAreWrittenTheWayPeopleReadThem(): void
    {
        $note = fn (int $bytes): string => $this->limitNote($this->get($this->tool($file, maxSize: $bytes)));
        $this->fileLimit = 0;
        $this->bodyLimit = 0;

        self::assertSame('Up to 1 B.', $note(1));
        self::assertSame('Up to 1,023 B.', $note(1023));
        self::assertSame('Up to 1 KB.', $note(1024));
        self::assertSame('Up to 1.5 KB.', $note(1536));
        // A limit is never rounded up: nobody is promised more than fits.
        self::assertSame('Up to 1.9 KB.', $note(2047));
        self::assertSame('Up to 2 MB.', $note(2 * 1024 * 1024));
        self::assertSame('Up to 1.2 GB.', $note((int) (1.25 * 1024 * 1024 * 1024)));
    }

    public function testASizeLimitHasToMakeSense(): void
    {
        $response = $this->get($this->tool($file, maxSize: 0));

        self::assertSame(500, $response->status);
        self::assertStringContainsString('maxSize', $response->body);
    }

    public function testAFileThatIsRefusedLeavesTheOldOneInPlace(): void
    {
        $tool = $this->tool($file, types: ['csv']);
        $first = $this->post($tool, files: ['report' => $this->arrived('good.csv', self::CSV)]);

        $second = $this->post($tool, $this->form($first), files: ['report' => $this->arrived('bad.png', 'x')]);

        self::assertSame('good.csv', $file?->name);
        self::assertSame(['good.csv'], $this->shown($second));
        self::assertCount(1, $this->errors($second));
        self::assertCount(1, $this->stored());
    }

    public function testAFileThatDidNotArriveIsSaidNotToHaveArrived(): void
    {
        $log = $this->captureErrorLog();

        foreach ([UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION, 99] as $code) {
            $response = $this->post($this->tool($file), files: ['report' => $this->arrived('orders.csv', '', $code)]);

            self::assertNull($file);
            self::assertSame(['"orders.csv" did not arrive. Please try again.'], $this->errors($response));
        }

        // The developer finds out why.
        self::assertStringContainsString('error ' . UPLOAD_ERR_NO_TMP_DIR, (string) file_get_contents($log));
    }

    // ------------------------------------------------------------------ names

    public function testTheNameOfAFileNeverNamesAFolder(): void
    {
        $names = ['../../etc/passwd', '..\\..\\windows\\win.ini', '/etc/passwd', 'C:\\autoexec.bat', '.htaccess', '..', '.', '', "a\0b.php", "new\nline.csv", str_repeat('x', 400) . '.csv'];

        foreach ($names as $given) {
            $this->post($this->tool($file), files: ['report' => $this->arrived($given, 'x')]);

            self::assertInstanceOf(Upload::class, $file);
            $name = $file->name;
            self::assertSame(basename($name), $name, 'the name of "' . addcslashes($given, "\0..\37") . '" names a folder');
            self::assertDoesNotMatchRegularExpression('/[\/\\\\\x00-\x1f]/', $name);
            self::assertNotContains($name, ['', '.', '..']);
            self::assertStringStartsNotWith('.', $name);
            self::assertLessThanOrEqual(150, mb_strlen($name));
        }
    }

    public function testANameIsShownAsText(): void
    {
        $response = $this->post($this->tool($file), files: ['report' => $this->arrived('"><img src=x onerror=alert(1)>.csv', 'x')]);

        self::assertStringNotContainsString('<img src=x', $response->body);
        self::assertCount(1, $this->shown($response));
    }

    // ---------------------------------------------- what a forged form cannot do

    public function testAHandleOnlyCountsForTheFieldItWasMadeFor(): void
    {
        $first = $second = null;
        $tool = function () use (&$first, &$second): void {
            $first = file_input('First');
            $second = file_input('Second');
        };
        $uploaded = $this->post($tool, files: ['first' => $this->arrived('a.csv', 'A')]);
        $handle = $this->handles($uploaded, 'first')[0];

        // The form claims the file of the first field for the second.
        $response = $this->post($tool, ['_file' => ['second' => [$handle]]]);

        self::assertNull($first);
        self::assertNull($second);
        self::assertSame([self::GONE], $this->errors($response));
    }

    public function testAHandleOnlyCountsForTheToolItWasMadeFor(): void
    {
        $tool = $this->tool($file);
        $uploaded = $this->post($tool, files: ['report' => $this->arrived('a.csv', 'A')]);
        $handle = $this->handles($uploaded)[0];

        // Another tool of the same user, with a field of the same name.
        $other = null;
        $this->kernel()->handle(
            new Request('POST', '/other', [], ['_token' => $this->token(), '_file' => ['report' => [$handle]]]),
            'other',
            function () use (&$other): void {
                $other = file_input('Report');
            },
        );

        self::assertNull($other);
        self::assertCount(1, $this->stored(), 'the file of the first tool was left alone');
    }

    public function testAHandleThatWasMadeUpIsNoFile(): void
    {
        foreach ([str_repeat('0', 32), '../../etc/passwd', '', ['nested'], str_repeat('a', 31)] as $forged) {
            $response = $this->post($this->tool($file), ['_file' => ['report' => [$forged]]]);

            self::assertSame(200, $response->status);
            self::assertNull($file);
        }

        // And a field that is not a list at all.
        $this->post($this->tool($file), ['_file' => ['report' => 'text'], 'report' => 'text']);
        self::assertNull($file);
        $this->post($this->tool($file), ['_file' => 'text']);
        self::assertNull($file);
    }

    public function testALinkCannotNameAFile(): void
    {
        $tool = $this->tool($file);
        $uploaded = $this->post($tool, files: ['report' => $this->arrived('a.csv', 'A')]);
        $handle = $this->handles($uploaded)[0];
        $this->post($tool, ['_click' => 'report:remove:' . $handle] + $this->form($uploaded));

        $this->get($tool, query: ['_file' => ['report' => [$handle]], '_click' => 'report:remove:' . $handle]);

        self::assertNull($file);
    }

    public function testFilesThatComeInAnOddShapeAreNotRead(): void
    {
        $odd = [
            'report' => ['name' => ['a' => ['b' => 'deep.csv']], 'tmp_name' => ['a' => ['b' => '/etc/passwd']], 'error' => ['a' => ['b' => 0]], 'size' => ['a' => ['b' => 5]]],
        ];
        $response = $this->post($this->tool($file), files: $odd);
        self::assertSame(200, $response->status);
        self::assertNull($file);

        foreach (['text', 5, null, ['name' => 'x.csv'], ['name' => 'x.csv', 'tmp_name' => ['list'], 'error' => 0, 'size' => 1]] as $shape) {
            $response = $this->post($this->tool($file), files: ['report' => $shape]);

            self::assertSame(200, $response->status);
            self::assertNull($file);
        }
    }

    public function testTextSentUnderTheNameOfAFileFieldIsNoFile(): void
    {
        // What the page script would send if it treated the field like a text field.
        $tool = $this->tool($file);
        $this->post($tool, ['report' => '[object File]']);
        self::assertNull($file);

        $this->get($tool);
        self::assertNull($file);
    }

    // ------------------------------------------------ files that are no longer there

    public function testAFileThatIsGoneIsSaidToBeGone(): void
    {
        $tool = $this->tool($file);
        $uploaded = $this->post($tool, files: ['report' => $this->arrived('orders.csv', self::CSV)]);
        unlink($file->path);

        $response = $this->post($tool, $this->form($uploaded));

        self::assertNull($file);
        self::assertSame([self::GONE], $this->errors($response));
        self::assertSame([], $this->shown($response));

        // Said once. The page that follows starts fresh.
        self::assertSame([], $this->errors($this->post($tool, $this->form($response))));
    }

    public function testAFileIsKeptForADay(): void
    {
        $this->now = 1_800_000_000;
        $tool = $this->tool($file);
        $uploaded = $this->post($tool, files: ['report' => $this->arrived('orders.csv', self::CSV)]);

        $this->now += 86400 - 60;
        $this->post($tool, $this->form($uploaded));
        self::assertSame('orders.csv', $file?->name);

        $this->now += 120;
        $response = $this->post($tool, $this->form($uploaded));
        self::assertNull($file);
        self::assertSame([self::GONE], $this->errors($response));
        self::assertSame([], $this->stored());
    }

    public function testTwoTabsEachKeepTheFileTheyShow(): void
    {
        $tool = $this->tool($file);
        // Both tabs show the empty field.
        $empty = $this->get($tool);

        $first = $this->post($tool, $this->form($empty), files: ['report' => $this->arrived('first.csv', 'one')]);
        $second = $this->post($tool, $this->form($empty), files: ['report' => $this->arrived('second.csv', 'two')]);

        // Each tab goes on with the file that it shows.
        $this->post($tool, $this->form($first));
        self::assertSame('first.csv', $file?->name);
        $this->post($tool, $this->form($second));
        self::assertSame('second.csv', $file?->name);
        self::assertCount(2, $this->stored());
    }

    public function testAFileThatAnotherTabRemovedIsGoneInThisTabToo(): void
    {
        $tool = $this->tool($file);
        $uploaded = $this->post($tool, files: ['report' => $this->arrived('orders.csv', self::CSV)]);
        $handle = $this->handles($uploaded)[0];

        // Two tabs show the file. One removes it.
        $this->post($tool, ['_click' => 'report:remove:' . $handle] + $this->form($uploaded));
        $other = $this->post($tool, $this->form($uploaded));

        self::assertNull($file);
        self::assertSame([self::GONE], $this->errors($other));
    }

    public function testAFieldThatIsNoLongerDrawnGivesItsFileUp(): void
    {
        $show = true;
        $file = null;
        $tool = function () use (&$show, &$file): void {
            $file = $show ? file_input('Report') : null;
        };
        $uploaded = $this->post($tool, files: ['report' => $this->arrived('orders.csv', self::CSV)]);
        self::assertCount(1, $this->stored());

        // The page that showed the file is sent, and the new page has no such field.
        $show = false;
        $this->post($tool, $this->form($uploaded));
        self::assertSame([], $this->stored());

        $show = true;
        $this->get($tool);
        self::assertNull($file);
    }

    public function testARunThatEndsAboveTheFieldGivesItsFileUpToo(): void
    {
        // A field that is not drawn loses what it held, as every input does. A run that
        // ends above the field does not draw it, whichever way it ends.
        $ways = [
            'an early return' => function () use (&$file): void {
                if (text('Customer') === '') {
                    return;
                }
                $file = file_input('Report');
            },
            'stop()' => function () use (&$file): void {
                if (text('Customer') === '') {
                    stop();
                }
                $file = file_input('Report');
            },
        ];

        foreach ($ways as $way => $tool) {
            $this->session = new ArraySession();
            $open = $this->post($tool, ['customer' => 'ava']);
            $uploaded = $this->post($tool, $this->form($open), files: ['report' => $this->arrived('orders.csv', self::CSV)]);
            self::assertSame('orders.csv', $file?->name, $way);
            self::assertCount(1, $this->stored(), $way);

            // The user empties the field above. The page no longer has a place for the file.
            $closed = $this->post($tool, ['customer' => ''] + $this->form($uploaded));
            self::assertSame([], $this->shown($closed), $way);
            self::assertSame([], $this->stored(), $way);

            // The field comes back empty, and nothing is called gone.
            $file = 'not asked yet';
            $again = $this->post($tool, ['customer' => 'ava'] + $this->form($closed));
            self::assertNull($file, $way);
            self::assertSame([], $this->errors($again), $way);
        }
    }

    public function testAForgottenFileIsGoneAtOnceWhateverHappensLaterInTheRun(): void
    {
        // forget() means "done with it". What was done with the file, an import for example,
        // has happened by then, so the file does not come back when the run fails afterwards.
        $tool = function (): void {
            $file = file_input('Report');
            if ($file !== null && button('Import')) {
                $file->forget();

                throw new \RuntimeException('something else went wrong');
            }
        };
        $uploaded = $this->post($tool, files: ['report' => $this->arrived('orders.csv', self::CSV)]);

        $failed = $this->post($tool, ['_click' => 'import'] + $this->form($uploaded));

        self::assertSame(500, $failed->status);
        self::assertSame([], $this->stored());
    }

    public function testARunThatFailsDeletesNothing(): void
    {
        $fail = false;
        $file = null;
        $tool = function () use (&$fail, &$file): void {
            $file = file_input('Report');
            if ($fail) {
                throw new \RuntimeException('boom');
            }
        };
        $uploaded = $this->post($tool, files: ['report' => $this->arrived('orders.csv', self::CSV)]);
        $handle = $this->handles($uploaded)[0];

        $fail = true;
        $failed = $this->post($tool, ['_click' => 'report:remove:' . $handle] + $this->form($uploaded));
        self::assertSame(500, $failed->status);
        self::assertCount(1, $this->stored());

        // What was saved before the failure still stands.
        $fail = false;
        $this->get($tool);
        self::assertSame('orders.csv', $file?->name);
    }

    public function testAFileStaysWhenTheFormIsTooLargeToBeRemembered(): void
    {
        // What a page keeps for the next view has a size limit. Over it nothing is kept.
        // The page still shows the file, so the file is not deleted for that.
        $tool = function () use (&$file): void {
            for ($n = 1; $n <= 40; $n++) {
                textarea('Notes ' . $n);
            }
            $file = file_input('Report');
        };
        $long = [];
        for ($n = 1; $n <= 40; $n++) {
            $long['notes-' . $n] = str_repeat('x', 8000);
        }
        $uploaded = $this->post($tool, files: ['report' => $this->arrived('orders.csv', self::CSV)]);

        $response = $this->post($tool, $long + $this->form($uploaded));
        self::assertSame([], $this->session->get('state') ?? [], 'the form was too large to be remembered');
        self::assertSame(['orders.csv'], $this->shown($response));
        self::assertCount(1, $this->stored());

        // The page goes on working with the file.
        $file = null;
        $this->post($tool, $this->form($response));
        self::assertSame('orders.csv', $file?->name);
        self::assertSame(self::CSV, $file?->contents());
    }

    public function testResetDeletesTheFilesOfTheTool(): void
    {
        $tool = $this->tool($file);
        $uploaded = $this->post($tool, files: ['report' => $this->arrived('orders.csv', self::CSV)]);

        $this->get($tool, query: $this->resetQuery($uploaded));

        self::assertSame([], $this->stored());
        $this->get($tool);
        self::assertNull($file);
    }

    // ----------------------------------------------------------------- forget

    /** A tool that imports a file and is done with it. */
    private function importer(mixed &$file): \Closure
    {
        return function () use (&$file): void {
            $file = file_input('Report');
            if ($file !== null && button('Import')) {
                $bytes = strlen($file->contents());
                $file->forget();
                success('Imported ' . $bytes . ' bytes.');
            }
        };
    }

    public function testForgetDeletesTheFileAndEmptiesTheField(): void
    {
        $tool = $this->importer($file);
        $uploaded = $this->post($tool, files: ['report' => $this->arrived('orders.csv', self::CSV)]);
        self::assertSame(1, $this->hits($uploaded, '//button[@id="b-import"]'));

        $done = $this->post($tool, ['_click' => 'import'] + $this->form($uploaded));

        self::assertSame(['Imported 14 bytes.'], $this->texts($done, '//*[contains(@class,"sui-success")]'));
        self::assertSame([], $this->stored());
        // The field is empty in the page of this very run, although it was drawn before the import.
        self::assertSame([], $this->shown($done));
        self::assertSame([], $this->handles($done));
        self::assertSame(1, $this->hits($done, '//input[@id="f-report"]'));

        // The next run has no file, and nothing is called gone.
        $next = $this->post($tool, $this->form($done));
        self::assertNull($file);
        self::assertSame([], $this->errors($next));
        $this->get($tool);
        self::assertNull($file);
    }

    public function testAForgottenFileCannotBeReadAnyMore(): void
    {
        $this->post($this->tool($file), files: ['report' => $this->arrived('orders.csv', self::CSV)]);
        self::assertInstanceOf(Upload::class, $file);

        $file->forget();
        $file->forget();

        $this->expectException(\RuntimeException::class);
        $file->contents();
    }

    public function testOneOfSeveralFilesCanBeForgotten(): void
    {
        $files = [];
        $tool = function () use (&$files): void {
            $files = file_input('Report', multiple: true);
            if (button('Drop the first')) {
                $files[0]->forget();
            }
        };
        $uploaded = $this->post($tool, files: ['report' => $this->arrivedTogether(['a.csv' => 'A', 'b.csv' => 'B'])]);

        $done = $this->post($tool, ['_click' => 'drop-the-first'] + $this->form($uploaded));

        self::assertSame(['b.csv'], $this->shown($done));
        self::assertCount(1, $this->stored());
        $this->get($tool);
        self::assertSame(['b.csv'], array_map(static fn (Upload $upload): string => $upload->name, $files));
    }

    // ---------------------------------------------------------- with a button

    public function testAButtonGetsAFileThatArrivesInTheSameRequest(): void
    {
        // Without the page script: the user chooses a file and presses the button.
        $got = null;
        $tool = function () use (&$got): void {
            $file = file_input('Report');
            if (button('Import')) {
                $got = $file?->contents();
            }
        };

        $this->post($tool, ['_click' => 'import'] + $this->form($this->get($tool)), files: ['report' => $this->arrived('orders.csv', self::CSV)]);

        self::assertSame(self::CSV, $got);
    }

    public function testAFileThatArrivesInARunThatLoadsThePageAgainIsKept(): void
    {
        $tool = function () use (&$file): void {
            $file = file_input('Report');
            if (button('Save')) {
                rerun();
            }
        };

        $response = $this->post($tool, ['_click' => 'save'] + $this->form($this->get($tool)), files: ['report' => $this->arrived('orders.csv', self::CSV)]);
        self::assertSame(303, $response->status);

        $file = null;
        $this->get($tool);
        self::assertSame('orders.csv', $file?->name);
        self::assertCount(1, $this->stored());
    }

    public function testARunThatLoadsThePageAgainBeforeItReachesTheFieldKeepsTheFile(): void
    {
        $tool = function () use (&$file): void {
            if (checkbox('Start over')) {
                rerun();
            }
            $file = file_input('Report');
        };
        $uploaded = $this->post($tool, files: ['report' => $this->arrived('orders.csv', self::CSV)]);

        // The run stops before it reaches the field. What the form held is kept as it is.
        $response = $this->post($tool, ['start-over' => '1'] + $this->form($uploaded));
        self::assertSame(303, $response->status);
        self::assertCount(1, $this->stored());
    }

    public function testAFileThatIsRemovedInARunThatLoadsThePageAgainStaysRemoved(): void
    {
        $tool = function () use (&$file): void {
            $file = file_input('Report');
            if (checkbox('Start over')) {
                rerun();
            }
        };
        $uploaded = $this->post($tool, files: ['report' => $this->arrived('orders.csv', self::CSV)]);
        $handle = $this->handles($uploaded)[0];

        // The form still names the file. The field ran before the page was loaded again, and it knows better.
        $response = $this->post($tool, ['_click' => 'report:remove:' . $handle, 'start-over' => '1'] + $this->form($uploaded));
        self::assertSame(303, $response->status);

        $file = 'not asked yet';
        $this->get($tool);
        self::assertNull($file);
        self::assertSame([], $this->stored());
    }

    public function testATableOfWhatIsInTheFileStartsAgainWhenAnotherFileArrives(): void
    {
        $rows = static fn (int $from): string => implode("\n", array_map(static fn (int $n): string => 'row ' . $n, range($from, $from + 59)));
        $tool = function (): void {
            $file = file_input('Report');
            table(array_map(static fn (string $line): array => ['line' => $line], $file === null ? [] : explode("\n", $file->contents())));
        };
        $pageNow = fn (Response $response): string => $this->texts($response, '//*[contains(@class,"sui-page-now")]')[0] ?? '';

        $first = $this->post($tool, files: ['report' => $this->arrived('a.txt', $rows(1))]);
        $second = $this->post($tool, ['_click' => 'table:page:2'] + $this->form($first));
        self::assertSame('Page 2 of 3', $pageNow($second));

        // Other rows from another file: page 2 of the old file means nothing here.
        $other = $this->post($tool, $this->form($second), files: ['report' => $this->arrived('b.txt', $rows(500))]);
        self::assertSame('Page 1 of 3', $pageNow($other));
        self::assertSame('row 500', $this->texts($other, '//table//tbody/tr[1]/td')[0]);
    }

    public function testATableOfWhatIsInTheFilesStartsAgainWhenAFileIsRemoved(): void
    {
        $rows = static fn (int $from): string => implode("\n", array_map(static fn (int $n): string => 'row ' . $n, range($from, $from + 29)));
        $tool = function (): void {
            $lines = [];
            foreach (file_input('Reports', multiple: true) as $file) {
                foreach (explode("\n", $file->contents()) as $line) {
                    $lines[] = ['line' => $line];
                }
            }
            table($lines);
        };
        $pageNow = fn (Response $response): string => $this->texts($response, '//*[contains(@class,"sui-page-now")]')[0] ?? '';

        $both = $this->post($tool, files: ['reports' => $this->arrivedTogether(['a.txt' => $rows(1), 'b.txt' => $rows(500), 'c.txt' => $rows(900)])]);
        $second = $this->post($tool, ['_click' => 'table:page:2'] + $this->form($both));
        self::assertSame('Page 2 of 4', $pageNow($second));

        $less = $this->post($tool, ['_click' => 'reports:remove:' . $this->handles($second, 'reports')[0]] + $this->form($second));
        self::assertSame('Page 1 of 3', $pageNow($less));
        self::assertSame('row 500', $this->texts($less, '//table//tbody/tr[1]/td')[0]);
    }

    // --------------------------------------------------------------- the rows

    public function testAnUploadedCsvFileIsReadAsRows(): void
    {
        $rows = null;
        $tool = function () use (&$rows): void {
            $file = file_input('Report', types: ['csv']);
            $rows = $file?->rows();
        };

        // As a German Excel saves it: semicolons, a mark at the start, Windows line ends.
        $this->post($tool, files: ['report' => $this->arrived('orders.csv', "\xEF\xBB\xBFemail;Betrag\r\nava@example.com;1,50\r\n\r\n")]);

        self::assertSame([['email' => 'ava@example.com', 'Betrag' => '1,50']], $rows);
    }

    public function testTheSeparatorAndTheEncodingOfTheRowsCanBeNamed(): void
    {
        $rows = null;
        $tool = function () use (&$rows): void {
            $rows = file_input('Report')?->rows(separator: '|', encoding: 'ISO-8859-1');
        };

        $this->post($tool, files: ['report' => $this->arrived('names.txt', "name|ort\nM\xFCller|K\xF6ln\n")]);

        self::assertSame([['name' => 'Müller', 'ort' => 'Köln']], $rows);
    }

    public function testAnUploadThatIsNotTextHasNoRows(): void
    {
        $rows = null;
        $tool = function () use (&$rows): void {
            $rows = file_input('Report')?->rows();
        };

        $this->post($tool, files: ['report' => $this->arrived('photo.csv', "\x89PNG\r\n\x1A\n\0\0\0\rIHDR")]);

        self::assertSame([], $rows);
    }

    // ---------------------------------------------------------------- the log

    public function testTheLogSaysWhichFileAButtonGot(): void
    {
        $tool = function (): void {
            file_input('Report');
            file_input('Photos', multiple: true);
            file_input('Nothing');
            button('Import');
        };
        $uploaded = $this->post($tool, files: [
            'report' => $this->arrived('orders.csv', self::CSV),
            'photos' => $this->arrivedTogether(['a.png' => 'A', 'b.png' => 'BB']),
        ]);
        self::assertSame([], $this->logged, 'a file that arrives is not an action');

        $this->post($tool, ['_click' => 'import'] + $this->form($uploaded));

        self::assertCount(1, $this->logged);
        $inputs = $this->logged[0]['inputs'];
        self::assertSame(['file' => 'orders.csv', 'bytes' => 14, 'sha256' => hash('sha256', self::CSV)], $inputs['report']);
        self::assertSame([
            ['file' => 'a.png', 'bytes' => 1, 'sha256' => hash('sha256', 'A')],
            ['file' => 'b.png', 'bytes' => 2, 'sha256' => hash('sha256', 'BB')],
        ], $inputs['photos']);
        self::assertNull($inputs['nothing']);
    }

    public function testTheLogStillNamesAFileThatTheToolForgot(): void
    {
        $tool = $this->importer($file);
        $uploaded = $this->post($tool, files: ['report' => $this->arrived('orders.csv', self::CSV)]);

        $this->post($tool, ['_click' => 'import'] + $this->form($uploaded));

        self::assertSame(['file' => 'orders.csv', 'bytes' => 14, 'sha256' => hash('sha256', self::CSV)], $this->logged[0]['inputs']['report']);
    }

    public function testRemovingAFileIsNotAnAction(): void
    {
        $tool = $this->tool($file);
        $uploaded = $this->post($tool, files: ['report' => $this->arrived('orders.csv', self::CSV)]);

        $this->post($tool, ['_click' => 'report:remove:' . $this->handles($uploaded)[0]] + $this->form($uploaded));

        self::assertSame([], $this->logged);
    }

    public function testASessionKeepsNoMoreThanTwoHundredFiles(): void
    {
        $this->now = 1_800_000_000;
        $tool = function (): void {
            file_input('Report', multiple: true);
        };

        // Eleven tools of one user, twenty files in each.
        for ($n = 1; $n <= 11; $n++) {
            $this->now += 10;
            $files = [];
            for ($f = 1; $f <= 20; $f++) {
                $files['tool-' . $n . '-file-' . $f . '.txt'] = 'x';
            }
            $this->kernel()->handle(
                new Request('POST', '/tool-' . $n, [], ['_token' => $this->token()], files: ['report' => $this->arrivedTogether($files)]),
                'tool-' . $n,
                $tool,
            );
        }

        // The oldest twenty made room: the files of the first tool.
        self::assertCount(200, $this->stored());
        $shown = [];
        $this->kernel()->handle(new Request('GET', '/tool-1'), 'tool-1', function () use (&$shown): void {
            $shown = file_input('Report', multiple: true);
        });
        self::assertSame([], $shown);
    }

    // ------------------------------------------------------- limits of the server

    public function testARequestThatIsTooLargeForPhpGetsItsOwnAnswer(): void
    {
        // PHP throws such a request away: the script sees no fields and no files.
        $this->bodyLimit = 1000;
        $tool = $this->tool($file);
        $this->token();

        $response = $this->kernel()->handle(new Request('POST', '/tool', contentLength: 5000), 'tool', $tool);

        self::assertSame(413, $response->status);
        self::assertStringContainsString('Too large', $response->body);
        self::assertStringContainsString('This is more than the server takes at once.', $response->body);
        self::assertSame('/tool', $this->attr($response, '//a[contains(@class,"sui-link")]', 'href'));

        // A small request without a token is what it always was.
        $response = $this->kernel()->handle(new Request('POST', '/tool', contentLength: 10), 'tool', $tool);
        self::assertSame(419, $response->status);
    }

    public function testNobodyLearnsAboutLimitsBeforeTheLoginCheck(): void
    {
        $this->bodyLimit = 1000;
        $this->config = new Config(auth: static fn (): ?string => null);

        $response = $this->kernel()->handle(new Request('POST', '/tool', contentLength: 5000), 'tool', $this->tool($file));

        self::assertSame(403, $response->status);
    }

    public function testThePageScriptLearnsWhatToSayWhenAServerRefusesTheFiles(): void
    {
        // A server in front of PHP can have a lower limit than the one the page names.
        $response = $this->get($this->tool($file));

        self::assertSame(
            'This is more than the server takes at once. Choose a smaller file, or fewer files.',
            $this->attr($response, '//input[@id="f-report"]', 'data-sui-refused'),
        );
    }

    public function testThePageScriptLearnsWhatToSayWhenAFileDoesNotGetThrough(): void
    {
        // The connection can break while a file is on its way. The script puts the name in.
        $response = $this->get($this->tool($file));

        self::assertSame(
            '"{name}" did not arrive. Please try again.',
            $this->attr($response, '//input[@id="f-report"]', 'data-sui-failed'),
        );
    }

    public function testThePageScriptLearnsHowMuchOneRequestMayCarry(): void
    {
        // Several files can each be small enough and still be too much together.
        $this->bodyLimit = 3000;
        $response = $this->get($this->tool($files, multiple: true));
        self::assertSame('3000', $this->attr($response, '//input[@id="f-report"]', 'data-sui-total'));

        // Without a limit in PHP there is nothing to check.
        $this->bodyLimit = 0;
        $this->session = new ArraySession();
        $response = $this->get($this->tool($files, multiple: true));
        self::assertNull($this->attr($response, '//input[@id="f-report"]', 'data-sui-total'));
    }

    public function testAFolderThatCannotBeUsedShowsOnTheFirstPageView(): void
    {
        // The one who sets a tool up sees the mistake, not the first user who uploads something.
        $blocker = (string) tempnam(sys_get_temp_dir(), 'script-ui-test-');
        $this->uploadFolder = $blocker . '/uploads';

        try {
            $response = $this->get($this->tool($file));

            self::assertSame(500, $response->status);
            self::assertStringContainsString('could not be made', $response->body);
            self::assertStringContainsString('uploads:', $response->body);
        } finally {
            unlink($blocker);
            $this->uploadFolder = null;
        }
    }

    public function testAToolSaysSoWhenPhpTakesNoUploads(): void
    {
        $this->uploadsOn = false;

        $response = $this->get($this->tool($file));

        self::assertSame(500, $response->status);
        self::assertStringContainsString('file_uploads', $response->body);
    }

    public function testAToolSaysSoWhenPhpTakesNoFileAtAll(): void
    {
        // max_file_uploads = 0 switches uploads off just the same.
        $this->countLimit = 0;

        $response = $this->get($this->tool($file));

        self::assertSame(500, $response->status);
        self::assertStringContainsString('max_file_uploads', $response->body);
    }

    // ---------------------------------------------------------------- language

    public function testTheTextsOfAFileFieldFollowTheLanguageOfThePage(): void
    {
        $tool = static fn (string $heading): \Closure => static function () use ($heading): void {
            title($heading);
            file_input('Report', types: ['csv'], maxSize: 1536, multiple: true);
        };

        $arabic = $this->post($tool('استيراد الطلبات'), files: ['report' => $this->arrivedTogether(['orders.csv' => self::CSV, 'photo.png' => 'x'])]);
        self::assertSame('المسموح: CSV. حتى 1.5 كيلوبايت.', $this->limitNote($arabic));
        self::assertSame(['«photo.png» غير مسموح هنا. المسموح: CSV.'], $this->errors($arabic));
        self::assertSame(['إزالة'], $this->texts($arabic, '//li[contains(@class,"sui-file")]//button'));
        self::assertSame(['14 بايت'], $this->texts($arabic, '//*[contains(@class,"sui-file-size")]'));

        // German is set. A title in Latin letters does not say which language it is.
        $this->session = new ArraySession();
        $this->config = new Config(auth: static fn (): string => 'tester', debug: true, language: 'de');
        $german = $this->post($tool('Bestellungen einlesen'), files: ['report' => $this->arrivedTogether(['big.csv' => str_repeat('x', 2000)])]);
        self::assertSame('Erlaubt: CSV. Bis 1,5 KB.', $this->limitNote($german));
        self::assertSame(['„big.csv“ ist größer als 1,5 KB.'], $this->errors($german));
    }

    // ------------------------------------------------------------- the markup

    public function testAFileFieldSitsInColumnsLikeAnyOtherField(): void
    {
        $left = $right = null;
        $tool = function () use (&$left, &$right): void {
            columns(
                function () use (&$left): void {
                    $left = file_input('Left', help: 'The export of last month');
                },
                function () use (&$right): void {
                    $right = file_input('Right', key: 'other');
                },
            );
        };

        $response = $this->post($tool, files: ['left' => $this->arrived('l.csv', 'L'), 'other' => $this->arrived('r.csv', 'RR')]);

        self::assertSame('l.csv', $left?->name);
        self::assertSame('r.csv', $right?->name);
        self::assertSame(2, $this->hits($response, '//div[contains(@class,"sui-col")]//input[@type="file"]'));
        self::assertSame(['l.csv'], $this->texts($response, '//div[contains(@class,"sui-col")][1]//*[contains(@class,"sui-file-name")]'));
        self::assertSame(['The export of last month'], $this->texts($response, '//div[contains(@class,"sui-col")][1]//p[@class="sui-help"]'));
        self::assertStringNotContainsString('<!--sui', $response->body);
    }

    public function testEveryPartOfAFieldCanBeFoundAgain(): void
    {
        $tool = $this->tool($files, multiple: true);
        $response = $this->post($tool, files: ['report' => $this->arrivedTogether(['a.csv' => 'A', 'b.csv' => 'B'])]);

        self::assertSame(['b-report:remove:0', 'b-report:remove:1'], $this->texts($response, '//li[contains(@class,"sui-file")]//button/@id'));
        // After a change the page script reads out which files the field holds.
        self::assertSame(['a.csv', 'b.csv'], $this->texts($response, '//div[contains(@class,"sui-field-file")]//*[@data-sui-status]'));
        // An error belongs to its field, for a screen reader too.
        $refused = $this->post($this->tool($file, maxSize: 1), files: ['report' => $this->arrived('big.csv', 'xx')]);
        self::assertSame('alert', $this->attr($refused, '//p[contains(@class,"sui-field-error")]', 'role'));
        self::assertSame('e-report', $this->attr($refused, '//p[contains(@class,"sui-field-error")]', 'id'));
        self::assertStringContainsString('e-report', (string) $this->attr($refused, '//input[@id="f-report"]', 'aria-describedby'));
    }
}
