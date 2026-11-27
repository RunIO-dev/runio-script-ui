<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Config;
use Runio\ScriptUi\Http\Request;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Session\ArraySession;
use Runio\ScriptUi\Tests\Support\ToolTestCase;
use Runio\ScriptUi\Texts;
use Runio\ScriptUi\ToolFinder;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\chart;
use function Runio\ScriptUi\multiselect;
use function Runio\ScriptUi\note;
use function Runio\ScriptUi\radio;
use function Runio\ScriptUi\table;
use function Runio\ScriptUi\title;

/**
 * The texts the library itself writes on a page ("Yes", "Reset", "No rows.")
 * and the language they are in.
 */
final class LanguageTest extends ToolTestCase
{
    private const ORDERS = [
        ['id' => 1, 'paid' => true],
        ['id' => 2, 'paid' => false],
        ['id' => 3, 'paid' => true],
    ];

    /** @var list<string> */
    private array $folders = [];

    protected function tearDown(): void
    {
        foreach ($this->folders as $folder) {
            foreach (scandir($folder) ?: [] as $file) {
                if (is_file($folder . '/' . $file)) {
                    unlink($folder . '/' . $file);
                }
            }
            rmdir($folder);
        }
        $this->folders = [];

        parent::tearDown();
    }

    /**
     * @param array<array-key,mixed> $texts
     */
    private function configure(?string $language = null, array $texts = [], ?bool $debug = true): void
    {
        $this->config = new Config(
            auth: static fn (): string => 'tester',
            debug: $debug,
            language: $language,
            texts: $texts,
        );
    }

    /**
     * A folder of tool files, removed again after the test.
     *
     * @param array<string,string> $files file name => content
     */
    private function folder(array $files = []): string
    {
        $folder = sys_get_temp_dir() . '/script-ui-language-' . bin2hex(random_bytes(6));
        mkdir($folder, 0777, true);
        foreach ($files as $name => $content) {
            file_put_contents($folder . '/' . $name, $content);
        }

        return $this->folders[] = $folder;
    }

    /** A page with a table of three rows on two pages, and a button that asks first. */
    private static function orders(string $title): \Closure
    {
        return static function () use ($title): void {
            title($title);
            table(self::ORDERS, selectable: true, pageSize: 2);
            button('Delete', confirm: 'Delete?', key: 'delete');
        };
    }

    private function foot(Response $response): string
    {
        return $this->texts($response, '//p[contains(@class,"sui-table-foot")]')[0] ?? '';
    }

    // ------------------------------------------------------- the built-in texts

    public function testEveryLanguageHasTheTextsOfEnglishAndNoOthers(): void
    {
        $english = require dirname(__DIR__) . '/resources/texts/en.php';
        self::assertIsArray($english);
        self::assertNotSame([], $english);

        foreach (Texts::BUILT_IN as $language) {
            $texts = require dirname(__DIR__) . '/resources/texts/' . $language . '.php';
            self::assertIsArray($texts);
            self::assertSame(array_keys($english), array_keys($texts), $language . ': same ids in the same order');

            foreach ($texts as $id => $text) {
                self::assertIsString($text, $language . ' ' . $id);
                self::assertNotSame('', trim($text), $language . ' ' . $id);

                // A text may only use the values its English version uses.
                preg_match_all('/\{[a-z]+\}/', $english[$id], $wanted);
                preg_match_all('/\{[a-z]+\}/', $text, $found);
                sort($wanted[0]);
                sort($found[0]);
                self::assertSame($wanted[0], $found[0], $language . ' ' . $id);
            }
        }
    }

    public function testAPageWithAnEnglishTitleIsInEnglish(): void
    {
        $tool = self::orders('Orders');
        $response = $this->get($tool);

        self::assertSame('en', $this->attr($response, '/html', 'lang'));
        self::assertSame('ltr', $this->attr($response, '/html', 'dir'));
        self::assertSame(['Reset'], $this->texts($response, '//a[contains(@class,"sui-reset")]'));
        self::assertSame('Rows 1 to 2 of 3 · 0 selected', $this->foot($response));
        self::assertSame('Select all rows on this page', $this->attr($response, '//button[@id="b-table:all"]', 'aria-label'));
        self::assertSame(['Previous', 'Page 1 of 2', 'Next'], $this->texts($response, '//*[contains(@class,"sui-pager")]/*'));
        self::assertSame('Pages', $this->attr($response, '//*[contains(@class,"sui-pager")]', 'aria-label'));
        self::assertSame(['Yes', 'No'], $this->texts($response, '//table//tbody/tr/td[3]'));
        self::assertSame('Select row 1', $this->attr($response, '//table//tbody//input[@type="checkbox"]', 'aria-label'));

        $response = $this->post($tool, ['_click' => 'delete']);
        self::assertSame(['Yes', 'Cancel'], $this->texts($response, '//*[@data-confirm]//button'));
        self::assertSame('Please confirm', $this->attr($response, '//*[@data-confirm]', 'aria-label'));
    }

    public function testAPageWithAnArabicTitleIsInArabic(): void
    {
        $tool = self::orders('الطلبات');
        $response = $this->get($tool);

        self::assertSame('ar', $this->attr($response, '/html', 'lang'));
        self::assertSame('rtl', $this->attr($response, '/html', 'dir'));
        self::assertSame(['إعادة تعيين'], $this->texts($response, '//a[contains(@class,"sui-reset")]'));
        self::assertSame('الصفوف 1 إلى 2 من 3 · المحدد: 0', $this->foot($response));
        self::assertSame('تحديد كل صفوف هذه الصفحة', $this->attr($response, '//button[@id="b-table:all"]', 'aria-label'));
        self::assertSame(['السابق', 'الصفحة 1 من 2', 'التالي'], $this->texts($response, '//*[contains(@class,"sui-pager")]/*'));
        self::assertSame('الصفحات', $this->attr($response, '//*[contains(@class,"sui-pager")]', 'aria-label'));
        self::assertSame(['نعم', 'لا'], $this->texts($response, '//table//tbody/tr/td[3]'));
        self::assertSame('تحديد الصف 1', $this->attr($response, '//table//tbody//input[@type="checkbox"]', 'aria-label'));

        $response = $this->post($tool, ['_click' => 'delete']);
        self::assertSame(['نعم', 'إلغاء'], $this->texts($response, '//*[@data-confirm]//button'));
        self::assertSame('يرجى التأكيد', $this->attr($response, '//*[@data-confirm]', 'aria-label'));
    }

    public function testTheLanguageCanBeSetForAllPages(): void
    {
        $this->configure('de');
        $tool = self::orders('Orders');
        $response = $this->get($tool);

        self::assertSame('de', $this->attr($response, '/html', 'lang'));
        self::assertSame('ltr', $this->attr($response, '/html', 'dir'));
        self::assertSame(['Zurücksetzen'], $this->texts($response, '//a[contains(@class,"sui-reset")]'));
        self::assertSame('Zeilen 1 bis 2 von 3 · 0 ausgewählt', $this->foot($response));
        self::assertSame('Alle Zeilen dieser Seite auswählen', $this->attr($response, '//button[@id="b-table:all"]', 'aria-label'));
        self::assertSame(['Zurück', 'Seite 1 von 2', 'Weiter'], $this->texts($response, '//*[contains(@class,"sui-pager")]/*'));
        self::assertSame(['Ja', 'Nein'], $this->texts($response, '//table//tbody/tr/td[3]'));

        $response = $this->post($tool, ['_click' => 'delete']);
        self::assertSame(['Ja', 'Abbrechen'], $this->texts($response, '//*[@data-confirm]//button'));
    }

    public function testASetLanguageLeavesAnArabicPageRightToLeft(): void
    {
        // The words of the page come from the script. Their direction is not a setting.
        $this->configure('de');
        $response = $this->get(self::orders('الطلبات'));

        self::assertSame('de', $this->attr($response, '/html', 'lang'));
        self::assertSame('rtl', $this->attr($response, '/html', 'dir'));
        self::assertSame(['Zurücksetzen'], $this->texts($response, '//a[contains(@class,"sui-reset")]'));
    }

    public function testALanguageThatIsWrittenFromTheRightTurnsThePage(): void
    {
        $this->configure('ar');
        $response = $this->get(self::orders('Orders'));

        self::assertSame('ar', $this->attr($response, '/html', 'lang'));
        self::assertSame('rtl', $this->attr($response, '/html', 'dir'));
    }

    public function testTheTextsFollowTheTitleFromWhereItIsSet(): void
    {
        // Before the title nobody knows the language of the page, so call title() first.
        $response = $this->get(function (): void {
            table([['id' => 1]], key: 'before');
            title('الطلبات');
            table([['id' => 1]], key: 'after');
        });

        self::assertSame(
            ['1 row', 'صف واحد'],
            $this->texts($response, '//p[contains(@class,"sui-table-foot")]'),
        );
    }

    public function testOneRowIsNotCalledRows(): void
    {
        $tool = static function (): void {
            title('Orders');
            table([['id' => 1]]);
            table(self::ORDERS, key: 'three');
        };

        self::assertSame(['1 row', '3 rows'], $this->texts($this->get($tool), '//p[contains(@class,"sui-table-foot")]'));

        $this->configure('de');
        self::assertSame(['1 Zeile', '3 Zeilen'], $this->texts($this->get($tool), '//p[contains(@class,"sui-table-foot")]'));
    }

    public function testLargeNumbersAreGroupedTheWayTheLanguageDoesIt(): void
    {
        $tool = static function (): void {
            title('Orders');
            table(array_map(static fn (int $id): array => ['id' => $id], range(1, 1200)), pageSize: 200);
        };

        self::assertSame('Rows 1 to 200 of 1,200', $this->foot($this->get($tool)));

        $this->configure('de');
        self::assertSame('Zeilen 1 bis 200 von 1.200', $this->foot($this->get($tool)));
    }

    public function testTheSearchOfATableSpeaksTheLanguageOfThePage(): void
    {
        $box = '//input[@name="_find[table]"]';
        $tool = static function (): void {
            title('الطلبات');
            table(self::ORDERS, search: true);
        };

        // "لا" is the "no" of the second row.
        $response = $this->post($tool, ['_find' => ['table' => 'لا']]);
        self::assertSame('بحث', $this->attr($response, $box, 'placeholder'));
        self::assertSame('البحث في هذا الجدول', $this->attr($response, $box, 'aria-label'));
        self::assertSame('صف واحد (من أصل 3)', $this->foot($response));

        $response = $this->post($tool, ['_find' => ['table' => 'xyz']]);
        self::assertSame(['لا توجد صفوف مطابقة للبحث.'], $this->texts($response, '//p[contains(@class,"sui-empty")]'));

        $this->configure('de');
        $tool = static function (): void {
            title('Bestellungen');
            table(self::ORDERS, search: true);
        };

        $response = $this->post($tool, ['_find' => ['table' => 'ja']]);
        self::assertSame('Suchen', $this->attr($response, $box, 'placeholder'));
        self::assertSame('Diese Tabelle durchsuchen', $this->attr($response, $box, 'aria-label'));
        self::assertSame('2 Zeilen (gefiltert aus 3)', $this->foot($response));

        $response = $this->post($tool, ['_find' => ['table' => 'xyz']]);
        self::assertSame(['Keine Zeile passt zur Suche.'], $this->texts($response, '//p[contains(@class,"sui-empty")]'));
    }

    public function testTheSelectionOfATableSpeaksTheLanguageOfThePage(): void
    {
        // All rows of the first page are ticked, then a search hides one of them.
        $pressed = function (\Closure $tool): Response {
            // Every language starts with nothing saved.
            $this->session = new ArraySession();
            $form = $this->form($this->post($tool, ['_click' => 'table:all'] + $this->form($this->get($tool))));
            $form['_find']['table'] = '2';

            return $this->post($tool, $form);
        };
        $tool = static fn (string $title): \Closure => static function () use ($title): void {
            title($title);
            table(self::ORDERS, selectable: true, pageSize: 2, search: true);
        };

        $response = $pressed($tool('Orders'));
        self::assertSame('1 row (filtered from 3) · 1 selected · 1 more hidden by the search', $this->foot($response));
        self::assertSame(['Clear selection'], $this->texts($response, '//button[@id="b-table:none"]'));

        $response = $pressed($tool('الطلبات'));
        self::assertSame('صف واحد (من أصل 3) · المحدد: 1 · المحدد المخفي بسبب البحث: 1', $this->foot($response));
        self::assertSame(['إلغاء التحديد'], $this->texts($response, '//button[@id="b-table:none"]'));

        $this->configure('de');
        $response = $pressed($tool('Bestellungen'));
        self::assertSame('1 Zeile (gefiltert aus 3) · 1 ausgewählt · 1 weitere von der Suche ausgeblendet', $this->foot($response));
        self::assertSame(['Auswahl aufheben'], $this->texts($response, '//button[@id="b-table:none"]'));
    }

    public function testTheNoteUnderALongChartTableIsTranslated(): void
    {
        $tool = static function (): void {
            title('Orders');
            chart(array_map(static fn (int $day): array => ['day' => 'Day ' . $day, 'total' => $day], range(1, 250)), x: 'day', y: 'total');
        };
        $note = '//figure//p[contains(@class,"sui-table-foot")]';

        self::assertSame(['Showing 200 of 250 rows'], $this->texts($this->get($tool), $note));

        $this->configure('de');
        self::assertSame(['200 von 250 Zeilen'], $this->texts($this->get($tool), $note));
    }

    public function testWhatTheScriptPassesWinsOverTheLanguage(): void
    {
        $this->configure('de');
        $tool = static function (): void {
            table([], empty: 'Nothing here');
            button('Archive', confirm: 'Archive?', yes: 'Do it', no: 'Keep');
        };

        $response = $this->post($tool, ['_click' => 'archive']);

        self::assertSame(['Nothing here'], $this->texts($response, '//p[contains(@class,"sui-empty")]'));
        self::assertSame(['Do it', 'Keep'], $this->texts($response, '//*[@data-confirm]//button'));
    }

    public function testAnEmptyTableAndAnEmptyChartSayItInTheLanguage(): void
    {
        $response = $this->get(function (): void {
            title('الطلبات');
            table([]);
            chart([], x: 'day', y: 'total');
        });

        self::assertSame(['لا توجد صفوف.', 'لا توجد بيانات للرسم.'], $this->texts($response, '//p[contains(@class,"sui-empty")]'));
    }

    public function testAGroupWithoutOptionsFollowsTheTitleFromWhereItIsSetToo(): void
    {
        // A group is drawn when the page is finished. Its words are still the ones
        // that were known where the script called it.
        $response = $this->get(function (): void {
            radio('Plan', []);
            title('الطلبات');
            radio('العميل', []);
        });

        self::assertSame(['Nothing to choose from.', 'لا توجد خيارات.'], $this->texts($response, '//p[contains(@class,"sui-choices-none")]'));
    }

    public function testAGroupWithoutOptionsSaysItInTheLanguage(): void
    {
        $arabic = $this->get(function (): void {
            title('الطلبات');
            radio('العميل', []);
        });
        self::assertSame(['لا توجد خيارات.'], $this->texts($arabic, '//p[contains(@class,"sui-choices-none")]'));

        $this->config = new Config(auth: static fn (): string => 'tester', debug: true, language: 'de');
        $german = $this->get(function (): void {
            multiselect('Kunden', []);
        });
        self::assertSame(['Nichts zur Auswahl.'], $this->texts($german, '//p[contains(@class,"sui-choices-none")]'));
    }

    public function testAChartIsDescribedInTheLanguage(): void
    {
        $tool = static function (): void {
            title('المبيعات');
            chart([['day' => 'Mon', 'total' => 3], ['day' => 'Tue', 'total' => 5]], x: 'day', y: ['total' => 'الإجمالي']);
        };
        $response = $this->get($tool);

        self::assertSame(['البيانات'], $this->texts($response, '//figure//details/summary'));
        self::assertSame('مخطط أعمدة: الإجمالي حسب Day', $this->attr($response, '//figure//*[local-name()="svg"]', 'aria-label'));

        $this->configure('de');
        $response = $this->get($tool);
        self::assertSame(['Daten'], $this->texts($response, '//figure//details/summary'));
        self::assertSame('Säulendiagramm: الإجمالي nach Day', $this->attr($response, '//figure//*[local-name()="svg"]', 'aria-label'));
    }

    public function testAFailureIsExplainedInTheLanguage(): void
    {
        $this->captureErrorLog();
        $tool = static function (): void {
            title('Orders');

            throw new \RuntimeException('kaboom');
        };

        $this->configure('de', debug: false);
        $response = $this->get($tool);
        self::assertSame(500, $response->status);
        self::assertSame(
            ['Beim Ausführen dieses Werkzeugs ist ein Fehler aufgetreten. Die Details stehen im Fehlerprotokoll des Servers.'],
            $this->texts($response, '//div[contains(@class,"sui-error")]'),
        );

        $this->configure('de', debug: true);
        self::assertSame(['Stack-Trace'], $this->texts($this->get($tool), '//details[contains(@class,"sui-trace")]/summary'));
    }

    public function testThePageScriptGetsItsMessageInTheLanguage(): void
    {
        $response = $this->get(self::orders('الطلبات'));

        self::assertSame(
            'لم يستجب الخادم. تأكد من أنه يعمل ثم حاول مرة أخرى. إذا انتهت جلستك فأعد تحميل الصفحة.',
            $this->attr($response, '//*[@id="sui-body"]', 'data-sui-offline'),
        );
        self::assertSame(['تحديث'], $this->texts($response, '//noscript//button'));
    }

    // ---------------------------------------------------- the pages around tools

    public function testTheNameOfTheCollectionFollowsTheLanguageUntilOneIsGiven(): void
    {
        $tool = static function (): void {
            title('Orders');
        };
        $open = fn (): Response => $this->kernel()->handle(new Request('GET', '/tool'), 'tool', $tool, null, '/');

        self::assertSame(['Tools'], $this->texts($open(), '//a[contains(@class,"sui-home")]'));
        self::assertSame(['Orders · Tools'], $this->texts($open(), '/html/head/title'));

        $this->configure('de');
        self::assertSame(['Werkzeuge'], $this->texts($open(), '//a[contains(@class,"sui-home")]'));
        self::assertSame(['Orders · Werkzeuge'], $this->texts($open(), '/html/head/title'));

        $this->config = new Config(auth: static fn (): string => 'tester', name: 'Back office', language: 'de');
        self::assertSame(['Back office'], $this->texts($open(), '//a[contains(@class,"sui-home")]'));
    }

    public function testTheListOfToolsUsesTheLanguage(): void
    {
        $folder = $this->folder();
        $index = fn (): Response => $this->kernel()->serve(new Request('GET', '/'), new ToolFinder($folder), '');

        $this->configure('ar');
        $response = $index();
        self::assertSame('rtl', $this->attr($response, '/html', 'dir'));
        self::assertSame(['الأدوات'], $this->texts($response, '//h1'));
        self::assertSame(
            ['لا توجد أدوات بعد. ضع ملف PHP في ' . $folder . ' وسيظهر هنا.'],
            $this->texts($response, '//div[contains(@class,"sui-info")]'),
        );
        self::assertSame([$folder], $this->texts($response, '//div[contains(@class,"sui-info")]/code'));
    }

    public function testAnArabicNameTurnsTheListOfToolsToArabic(): void
    {
        $folder = $this->folder(['hello.php' => "<?php\n// Hello\n"]);
        $this->config = new Config(auth: static fn (): string => 'tester', name: 'أدوات المتجر');

        $response = $this->kernel()->serve(new Request('GET', '/missing'), new ToolFinder($folder), '');

        self::assertSame(404, $response->status);
        self::assertSame('rtl', $this->attr($response, '/html', 'dir'));
        self::assertSame(['غير موجود'], $this->texts($response, '//h1'));
        self::assertSame(['كل الأدوات'], $this->texts($response, '//a[contains(@class,"sui-link")]'));
    }

    public function testPagesThatTurnSomeoneAwayUseTheLanguage(): void
    {
        $tool = static function (): void {
            note('never shown');
        };

        $this->configure('de');
        $this->token();
        $response = $this->kernel()->handle(new Request('POST', '/tool', [], ['_token' => 'wrong']), 'tool', $tool);
        self::assertSame(419, $response->status);
        self::assertSame('de', $this->attr($response, '/html', 'lang'));
        self::assertSame(['Diese Seite ist abgelaufen'], $this->texts($response, '//h1'));
        self::assertSame(['Seite erneut öffnen'], $this->texts($response, '//a[contains(@class,"sui-link")]'));

        $response = $this->kernel()->handle(new Request('DELETE', '/tool'), 'tool', $tool);
        self::assertSame(405, $response->status);
        self::assertSame('GET, HEAD, POST', $response->header('Allow'));
        self::assertSame(['Methode nicht erlaubt'], $this->texts($response, '//h1'));

        $this->config = new Config(auth: static fn (): ?string => null, language: 'de');
        $response = $this->get($tool);
        self::assertSame(403, $response->status);
        self::assertSame(['Nicht erlaubt'], $this->texts($response, '//h1'));
        self::assertSame(['Sie haben keinen Zugriff auf diese Seite.'], $this->texts($response, '//p[contains(@class,"sui-note")]'));
    }

    // ------------------------------------------------------------- own texts

    public function testATextCanBeReplacedForOneLanguage(): void
    {
        $this->configure(texts: ['en' => ['page.reset' => 'Start over', 'confirm.no' => 'Back']]);

        $tool = self::orders('Orders');
        self::assertSame(['Start over'], $this->texts($this->get($tool), '//a[contains(@class,"sui-reset")]'));
        self::assertSame(['Yes', 'Back'], $this->texts($this->post($tool, ['_click' => 'delete']), '//*[@data-confirm]//button'));

        // An Arabic page keeps its Arabic texts.
        self::assertSame(['إعادة تعيين'], $this->texts($this->get(self::orders('الطلبات')), '//a[contains(@class,"sui-reset")]'));
    }

    public function testAnotherLanguageCanBeAdded(): void
    {
        $this->configure('fr', ['fr' => ['confirm.yes' => 'Oui', 'confirm.no' => 'Annuler']]);

        $tool = self::orders('Commandes');
        $response = $this->post($tool, ['_click' => 'delete']);

        self::assertSame('fr', $this->attr($response, '/html', 'lang'));
        self::assertSame(['Oui', 'Annuler'], $this->texts($response, '//*[@data-confirm]//button'));
        // What the translation leaves out stays English.
        self::assertSame(['Reset'], $this->texts($response, '//a[contains(@class,"sui-reset")]'));
    }

    public function testARegionKeepsTheTextsOfItsLanguage(): void
    {
        // Built-in German, own texts for German, own texts for Austria: the closest one wins.
        $this->configure('de-AT', [
            'de' => ['confirm.no' => 'Zurück', 'page.reset' => 'Leeren'],
            'de-AT' => ['page.reset' => 'Ausleeren'],
        ]);

        $response = $this->post(self::orders('Bestellungen'), ['_click' => 'delete']);

        self::assertSame('de-AT', $this->attr($response, '/html', 'lang'));
        self::assertSame(['Ja', 'Zurück'], $this->texts($response, '//*[@data-confirm]//button'));
        self::assertSame(['Ausleeren'], $this->texts($response, '//a[contains(@class,"sui-reset")]'));
    }

    public function testARegionOfAnAddedLanguageNeedsNoTextsOfItsOwn(): void
    {
        $this->configure('fr-CA', ['fr' => ['confirm.yes' => 'Oui']]);

        $response = $this->post(self::orders('Commandes'), ['_click' => 'delete']);

        self::assertSame('fr-CA', $this->attr($response, '/html', 'lang'));
        self::assertSame(['Oui', 'Cancel'], $this->texts($response, '//*[@data-confirm]//button'));
    }

    public function testOwnTextsAreTextAndNeverHtml(): void
    {
        $evil = '<img src=x onerror=alert(1)>';
        $quote = '" onmouseover="alert(1)';
        $this->captureErrorLog();
        $this->configure(debug: false, texts: ['en' => [
            'index.title' => $evil,
            'page.reset' => $evil,
            'page.reset_hint' => $quote,
            'page.update' => $evil,
            'page.offline' => $quote,
            'table.rows' => $evil . ' {count}',
            'table.range' => $evil . ' {from} {to} {count}',
            'table.selected' => $evil . ' {count}',
            'table.pages' => $quote,
            'table.search' => $quote,
            'table.search_label' => $quote,
            'table.filtered' => '{rows} ' . $evil . ' {count}',
            'table.no_match' => $evil,
            'table.page' => $evil . ' {page} {pages}',
            'table.previous' => $evil,
            'table.next' => $evil,
            'table.select_page' => $quote,
            'table.clear' => $evil,
            'table.selected_hidden' => $evil . ' {count}',
            'table.select_row' => $quote . ' {row}',
            'cell.yes' => $evil,
            'confirm.title' => $quote,
            'confirm.yes' => $evil,
            'chart.data' => $evil,
            'chart.bar' => $quote . ' {series} {axis}',
            'failure.text' => $evil,
            'index.empty' => $evil . ' {folder}',
            'error.not_found' => $evil,
            'error.no_address' => $evil,
        ]]);

        $tool = function (): void {
            title('Orders');
            table(self::ORDERS, selectable: true, search: true);
            table(array_map(static fn (int $id): array => ['id' => $id], range(1, 30)), key: 'long');
            table(self::ORDERS, key: 'found', search: true);
            table(self::ORDERS, key: 'none', search: true);
            chart([['day' => 'Mon', 'total' => 3]], x: 'day', y: 'total');
            button('Delete', confirm: 'Delete?', key: 'delete');

            throw new \RuntimeException('kaboom');
        };

        // The first two rows are ticked. A search then hides the second one: it
        // looks for a word of the text that stands for "yes" here.
        $boxes = $this->texts($this->get($tool), '//input[@name="_sel[table][]"]/@value');
        $response = $this->post($tool, [
            '_click' => 'delete',
            '_sel' => ['table' => [$boxes[0], $boxes[1]]],
            '_find' => ['table' => 'img', 'found' => '2', 'none' => 'nothing like it'],
        ]);

        // Nothing of it became markup ...
        self::assertSame(0, $this->hits($response, '//img'));
        self::assertSame(0, $this->hits($response, '//*[@onmouseover]'));

        // ... and every text is on the page, exactly as it was given.
        self::assertSame(['Orders · ' . $evil], $this->texts($response, '/html/head/title'));
        self::assertSame([$evil], $this->texts($response, '//a[contains(@class,"sui-reset")]'));
        self::assertSame($quote, $this->attr($response, '//a[contains(@class,"sui-reset")]', 'title'));
        self::assertSame([$evil], $this->texts($response, '//noscript//button'));
        self::assertSame($quote, $this->attr($response, '//*[@id="sui-body"]', 'data-sui-offline'));
        self::assertSame($evil . ' 2 ' . $evil . ' 3 · ' . $evil . ' 1 · ' . $evil . ' 1', $this->foot($response));
        self::assertSame($quote, $this->attr($response, '//button[@id="b-table:all"]', 'aria-label'));
        self::assertSame($quote, $this->attr($response, '//button[@id="b-table:all"]', 'title'));
        self::assertSame([$evil], $this->texts($response, '//button[@id="b-table:none"]'));
        self::assertSame($evil . ' 1 25 30', $this->texts($response, '//p[contains(@class,"sui-table-foot")]')[1]);
        self::assertSame([$evil, $evil . ' 1 2', $evil], $this->texts($response, '//*[contains(@class,"sui-pager")]/*'));
        self::assertSame($quote, $this->attr($response, '//*[contains(@class,"sui-pager")]', 'aria-label'));
        self::assertSame($quote, $this->attr($response, '//input[@name="_find[found]"]', 'placeholder'));
        self::assertSame($quote, $this->attr($response, '//input[@name="_find[found]"]', 'aria-label'));
        self::assertSame('1 row ' . $evil . ' 3', $this->texts($response, '//p[contains(@class,"sui-table-foot")]')[2]);
        self::assertSame([$evil], $this->texts($response, '//p[contains(@class,"sui-empty")]'));
        self::assertSame($quote . ' 1', $this->attr($response, '//table//tbody//input[@type="checkbox"]', 'aria-label'));
        self::assertSame([$evil, $evil], $this->texts($response, '//table[1]//tbody/tr/td[3]'));
        self::assertSame($quote, $this->attr($response, '//*[@data-confirm]', 'aria-label'));
        self::assertSame([$evil, 'Cancel'], $this->texts($response, '//*[@data-confirm]//button'));
        self::assertSame([$evil], $this->texts($response, '//figure//details/summary'));
        self::assertSame($quote . ' Total Day', $this->attr($response, '//figure//*[local-name()="svg"]', 'aria-label'));
        self::assertSame([$evil], $this->texts($response, '//div[contains(@class,"sui-error")]'));

        $folder = $this->folder();
        $response = $this->kernel()->serve(new Request('GET', '/'), new ToolFinder($folder), '');
        self::assertSame(0, $this->hits($response, '//img'));
        self::assertSame([$evil], $this->texts($response, '//h1'));
        self::assertSame([$evil . ' ' . $folder . ''], $this->texts($response, '//div[contains(@class,"sui-info")]'));
        self::assertSame([$folder], $this->texts($response, '//div[contains(@class,"sui-info")]/code'));

        $response = $this->kernel()->serve(new Request('GET', '/a/b'), new ToolFinder($folder), '');
        self::assertSame(404, $response->status);
        self::assertSame(0, $this->hits($response, '//img'));
        self::assertSame([$evil], $this->texts($response, '//h1'));
        self::assertSame([$evil], $this->texts($response, '//p[contains(@class,"sui-note")]'));
    }

    // --------------------------------------------------------- wrong settings

    public function testALanguageWithoutTextsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("texts: ['fr' =>");

        new Config(language: 'fr');
    }

    public function testALanguageHasToLookLikeALanguageCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('language code');

        new Config(language: 'de"><script>');
    }

    public function testATextThatDoesNotExistIsRefused(): void
    {
        // A misspelled id would otherwise change nothing, and nobody would notice.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"page.rest"');

        new Config(texts: ['en' => ['page.rest' => 'Start over']]);
    }

    public function testTextsAreGivenPerLanguage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("['de' => ['page.reset' =>");

        new Config(texts: ['page.reset' => 'Start over']);
    }

    public function testATextHasToBeText(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"page.reset"');

        new Config(texts: ['en' => ['page.reset' => 5]]);
    }
}
