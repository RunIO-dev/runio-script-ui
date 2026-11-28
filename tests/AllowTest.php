<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Config;
use Runio\ScriptUi\Http\Request;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Keys;
use Runio\ScriptUi\Tests\Support\ToolTestCase;
use Runio\ScriptUi\ToolFinder;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\success;
use function Runio\ScriptUi\title;

/**
 * Who may open which tool. The login check says who is asking, for the whole
 * folder. "allow" says, for that user and one tool, yes or no: the tool is in
 * the list or it is not, and it opens or it does not.
 */
final class AllowTest extends ToolTestCase
{
    private string $dir;

    /** @var list<array{?string, string}> what the access check was asked: the user and the tool */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->asked = [];
        $this->dir = sys_get_temp_dir() . '/script-ui-allow-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);

        // Every tool leaves a line when it runs. A tool that was refused must leave none.
        $tool = static fn (string $title, string $id): string => <<<PHP
            <?php
            // $title

            use function Runio\\ScriptUi\\{title, text, button, success, download};

            file_put_contents(__DIR__ . '/ran.log', "$id\\n", FILE_APPEND);

            title('$title');
            text('Note');
            download('Export', [['id' => 1]], '$id.csv');
            if (button('Go')) {
                success('Done.');
            }
            PHP;

        file_put_contents($this->dir . '/refund.php', $tool('Refund orders', 'refund'));
        file_put_contents($this->dir . '/payroll.php', $tool('Payroll', 'payroll'));
        file_put_contents($this->dir . '/report.php', $tool('Sales report', 'report'));
        file_put_contents($this->dir . '/_helper.php', "<?php // not a tool\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);

        parent::tearDown();
    }

    /** Ava may open the refunds and the report. Nobody may open the payroll. */
    private function rules(): \Closure
    {
        return function (?string $user, string $tool): bool {
            $this->asked[] = [$user, $tool];

            return $user === 'ava' && in_array($tool, ['refund', 'report'], true);
        };
    }

    private function with(?callable $allow, ?callable $auth = null, ?string $language = null): void
    {
        $this->config = new Config(
            auth: $auth ?? static fn (): string => 'ava',
            log: function (array $entry): void {
                $this->logged[] = $entry;
            },
            debug: true,
            language: $language,
            allow: $allow,
        );
    }

    /**
     * @param array<string,mixed>  $post
     * @param array<string,string> $headers
     */
    private function serve(string $method, string $path, array $post = [], array $headers = [], string $base = ''): Response
    {
        if ($method === 'POST') {
            $post += ['_token' => $this->token()];
        }

        return $this->kernel()->serve(new Request($method, $path, [], $post, $headers), new ToolFinder($this->dir), $base);
    }

    /**
     * The token belongs to the session and not to a tool. It is made by a page
     * view that the rules of a test have no say in, so no test counts it.
     */
    protected function token(): string
    {
        $config = $this->config;
        $this->config = null;

        try {
            return parent::token();
        } finally {
            $this->config = $config;
        }
    }

    /** @return list<string> the tools that ran, in the order they ran */
    private function ran(): array
    {
        $log = $this->dir . '/ran.log';

        return is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [];
    }

    /** @return list<string> */
    private function listed(Response $response): array
    {
        return $this->texts($response, '//a[contains(@class,"sui-card")]//strong');
    }

    // ----------------------------------------------------------------- the list

    public function testWithoutAnAccessCheckEveryToolIsOpenAsBefore(): void
    {
        $this->with(null);

        self::assertSame(['Payroll', 'Refund orders', 'Sales report'], $this->listed($this->serve('GET', '/')));
        foreach (['/refund', '/payroll', '/report'] as $path) {
            self::assertSame(200, $this->serve('GET', $path)->status, $path);
        }
        self::assertSame(['refund', 'payroll', 'report'], $this->ran());
    }

    public function testTheListShowsOnlyWhatTheUserMayOpen(): void
    {
        $this->with($this->rules());

        $response = $this->serve('GET', '/');

        self::assertSame(200, $response->status);
        self::assertSame(['Refund orders', 'Sales report'], $this->listed($response));
        self::assertSame(['/refund', '/report'], $this->texts($response, '//a[contains(@class,"sui-card")]/@href'));
        self::assertStringNotContainsString('Payroll', $response->body);
        self::assertStringNotContainsString('payroll', $response->body);

        // Asked once for every tool of the folder, with the name the login check gave.
        sort($this->asked);
        self::assertSame([['ava', 'payroll'], ['ava', 'refund'], ['ava', 'report']], $this->asked);
        self::assertSame([], $this->ran(), 'a list runs no tool');
    }

    public function testAUserWhoMayOpenNothingIsToldSo(): void
    {
        $this->with(static fn (): bool => false);

        $response = $this->serve('GET', '/');

        self::assertSame(200, $response->status);
        self::assertSame([], $this->listed($response));
        self::assertSame(['There is no tool here that you may open.'], $this->texts($response, '//div[contains(@class,"sui-callout")]'));
        // The hint for whoever sets the tools up names a folder of the server. It is not for this user.
        self::assertStringNotContainsString('Put a PHP file', $response->body);
        self::assertStringNotContainsString($this->dir, $response->body);

        // Your own words for it are text like any other, never HTML.
        $this->config = new Config(
            auth: static fn (): string => 'ava',
            debug: true,
            texts: ['en' => ['index.none_allowed' => 'Ask <b>IT</b> & wait.']],
            allow: static fn (): bool => false,
        );
        $own = $this->serve('GET', '/');
        self::assertSame(['Ask <b>IT</b> & wait.'], $this->texts($own, '//div[contains(@class,"sui-callout")]'));
        self::assertSame(0, $this->hits($own, '//div[contains(@class,"sui-callout")]//b'));
    }

    // ----------------------------------------------------------------- one tool

    public function testAToolThatIsAllowedWorksAsAlways(): void
    {
        $this->with($this->rules());

        $page = $this->serve('GET', '/refund');
        self::assertSame(200, $page->status);
        self::assertSame(['Refund orders'], $this->texts($page, '//h1'));
        self::assertSame([['ava', 'refund']], $this->asked, 'asked once, about this tool only');

        $pressed = $this->serve('POST', '/refund', ['_click' => 'go', 'note' => 'late']);
        self::assertSame(200, $pressed->status);
        self::assertSame(['Done.'], $this->texts($pressed, '//div[contains(@class,"sui-success")]'));
        self::assertSame('Go', $this->logged[0]['action']);
        self::assertSame(['refund', 'refund'], $this->ran());
    }

    public function testAToolThatIsNotAllowedSaysSoAndDoesNotRun(): void
    {
        $this->with($this->rules());

        $response = $this->serve('GET', '/admin/tools/payroll', base: '/admin/tools');

        self::assertSame(403, $response->status);
        self::assertSame(['Not allowed'], $this->texts($response, '//h1'));
        self::assertSame(['You may not open this tool.'], $this->texts($response, '//p[contains(@class,"sui-note")]'));
        // The way back leads to the tools this user may open.
        self::assertSame(['All tools'], $this->texts($response, '//a[contains(@class,"sui-link")]'));
        self::assertSame('/admin/tools/', $this->attr($response, '//a[contains(@class,"sui-link")]', 'href'));
        // The page says nothing about the tool: not its title, and no form of it.
        self::assertStringNotContainsString('Payroll', $response->body);
        self::assertSame(0, $this->hits($response, '//form'));
        self::assertNotNull($response->header('Content-Security-Policy'));

        self::assertSame([], $this->ran());
        self::assertSame([['ava', 'payroll']], $this->asked);
    }

    public function testNothingOfARefusedToolRunsWhateverTheRequestIs(): void
    {
        $this->with($this->rules());

        $requests = [
            'a pressed button' => [['_click' => 'go', 'note' => 'x'], []],
            'a download' => [['_click' => 'export'], []],
            'the page script' => [['note' => 'x'], ['X-Script-Ui' => 'fragment']],
            'a page that refreshes' => [['note' => 'x'], ['X-Script-Ui' => 'refresh']],
            'a report of the page script' => [['_open' => ['box' => '1']], ['X-Script-Ui' => 'layout']],
        ];

        foreach ($requests as $case => [$post, $headers]) {
            $response = $this->serve('POST', '/payroll', $post, $headers);

            self::assertSame(403, $response->status, $case);
            self::assertStringNotContainsString('Payroll', $response->body, $case);
            self::assertNull($response->header('Content-Disposition'), $case);
            self::assertNull($response->header('X-Script-Ui-Fragment'), $case . ': not taken for an answer of the tool');
        }

        // The same answer for requests that a tool that is open answers in a way of
        // its own. Any other answer would say something about a tool that is closed.
        $finder = new ToolFinder($this->dir);
        $others = [
            // "This page has expired" would say that the tool is there to be used.
            'a form that was not sent from the page' => new Request('POST', '/payroll', [], ['_click' => 'go']),
            // "Too large" comes from the limits of PHP. Nothing of the request arrived.
            'more than PHP takes' => new Request('POST', '/payroll', contentLength: $this->bodyLimit + 1),
            'a file' => new Request('POST', '/payroll', [], ['_token' => $this->token()], files: ['note' => $this->arrived('pay.csv', 'x')]),
            'a method that no tool answers' => new Request('DELETE', '/payroll'),
        ];
        foreach ($others as $case => $request) {
            $response = $this->kernel()->serve($request, $finder, '');

            self::assertSame(403, $response->status, $case);
            self::assertSame(['You may not open this tool.'], $this->texts($response, '//p[contains(@class,"sui-note")]'), $case);
            self::assertNull($response->header('Allow'), $case);
        }
        self::assertSame([], $this->stored(), 'the file was not taken');

        self::assertSame([], $this->ran());
        self::assertSame([], $this->logged, 'nothing was pressed, so nothing is logged');
        self::assertNull($this->session->get('state'), 'and nothing is remembered');
    }

    public function testWhatARefusedToolRememberedIsLeftAlone(): void
    {
        $open = true;
        $this->with(static function () use (&$open): bool {
            return $open;
        });

        // While the tool was open, the user typed something, and the page kept it.
        $page = $this->serve('POST', '/refund', ['note' => 'call back on Monday']);
        $reset = (string) $this->attr($page, '//a[contains(@class,"sui-reset")]', 'href');
        self::assertMatchesRegularExpression('~^/refund\?_reset=[0-9a-f]{24}$~', $reset);
        $kept = $this->session->get('state');
        self::assertNotNull($kept);

        // Access is taken away. The link that forgets the inputs does nothing now.
        $open = false;
        parse_str((string) parse_url($reset, PHP_URL_QUERY), $query);
        $response = $this->kernel()->serve(new Request('GET', '/refund', $query), new ToolFinder($this->dir), '');
        self::assertSame(403, $response->status);
        self::assertSame($kept, $this->session->get('state'));

        // Given back, the tool is as the user left it.
        $open = true;
        self::assertSame('call back on Monday', $this->attr($this->serve('GET', '/refund'), '//input[@id="f-note"]', 'value'));
    }

    public function testAToolThatDoesNotExistIsNotFoundAndNobodyIsAsked(): void
    {
        $this->with($this->rules());

        // The name of the file is not an address either, whoever asks.
        foreach (['/missing', '/_helper', '/ran.log', '/PAYROLL', '/payroll.php', '/refund.php'] as $path) {
            self::assertSame(404, $this->serve('GET', $path)->status, $path);
        }
        self::assertSame([], $this->asked);
        self::assertSame([], $this->ran());
    }

    public function testTheRuleHoldsWhereToolsAreChosenInTheQueryString(): void
    {
        $this->with($this->rules());
        $finder = new ToolFinder($this->dir);

        // Without "base" one script shows every tool, and "?_tool=" says which.
        $list = $this->kernel()->serve(new Request('GET', '/admin.php'), $finder, null);
        self::assertSame(['Refund orders', 'Sales report'], $this->listed($list));
        self::assertSame(['/admin.php?_tool=refund', '/admin.php?_tool=report'], $this->texts($list, '//a[contains(@class,"sui-card")]/@href'));

        $refused = $this->kernel()->serve(new Request('GET', '/admin.php', ['_tool' => 'payroll']), $finder, null);
        self::assertSame(403, $refused->status);
        self::assertSame('/admin.php', $this->attr($refused, '//a[contains(@class,"sui-link")]', 'href'));

        $pressed = $this->kernel()->serve(new Request('POST', '/admin.php', ['_tool' => 'payroll'], ['_click' => 'go', '_token' => $this->token()]), $finder, null);
        self::assertSame(403, $pressed->status);

        self::assertSame(200, $this->kernel()->serve(new Request('GET', '/admin.php', ['_tool' => 'refund']), $finder, null)->status);
        self::assertSame(['refund'], $this->ran());
    }

    public function testTheLoginCheckComesFirst(): void
    {
        $this->with($this->rules(), static fn (): ?string => null);

        foreach (['/', '/refund', '/payroll'] as $path) {
            $response = $this->serve('GET', $path);

            self::assertSame(403, $response->status, $path);
            self::assertSame(['You do not have access to this page.'], $this->texts($response, '//p[contains(@class,"sui-note")]'), $path);
        }
        self::assertSame([], $this->asked, 'nobody is asked about somebody who is not logged in');
        self::assertSame([], $this->ran());
    }

    public function testTheCheckIsAskedOnEveryRequest(): void
    {
        $open = true;
        $this->with(static function () use (&$open): bool {
            return $open;
        });

        self::assertSame(200, $this->serve('GET', '/payroll')->status);

        // Access is taken away. The very next request is refused, also one that
        // comes from a page the user still has open.
        $open = false;
        self::assertSame(403, $this->serve('POST', '/payroll', ['_click' => 'go'])->status);
        self::assertSame(['payroll'], $this->ran());
    }

    public function testTheCheckGetsWhatTheLoginCheckReturned(): void
    {
        // An application often has more to say about a user than a name: here an
        // object with roles. The login check hands it over, and the access check gets it as it is.
        $member = new class () {
            /** @var list<string> */
            public array $roles = ['refund'];
        };
        $got = [];
        $this->with(
            static function (mixed $user, string $tool) use (&$got): bool {
                $got[] = $user;

                return in_array($tool, $user->roles, true);
            },
            static fn (): object => $member,
        );

        self::assertSame(200, $this->serve('GET', '/refund')->status);
        self::assertSame(403, $this->serve('GET', '/payroll')->status);
        self::assertSame([$member, $member], $got);

        // The list asks in the same way, once for every tool of the folder.
        $got = [];
        self::assertSame(['Refund orders'], $this->listed($this->serve('GET', '/')));
        self::assertSame([$member, $member, $member], $got);

        // A name is a name, as before.
        $this->asked = [];
        $this->with($this->rules(), static fn (): string => 'ava');
        $this->serve('GET', '/report');
        self::assertSame([['ava', 'report']], $this->asked);
    }

    public function testALoginCheckThatOnlySaysYesNamesNobody(): void
    {
        // "true" lets somebody in without saying who. The access check gets null
        // then, as user() does. In a loose comparison true is equal to every
        // name, and a rule such as in_array($user, $team) would open every tool.
        $got = [];
        $this->with(
            static function (mixed $user, string $tool) use (&$got): bool {
                $got[] = $user;

                return in_array($user, ['bob', 'carol']);
            },
            static fn (): bool => true,
        );

        self::assertSame(403, $this->serve('GET', '/payroll')->status);
        self::assertSame([], $this->listed($this->serve('GET', '/')));
        self::assertSame([null, null, null, null], $got);
        self::assertSame([], $this->ran());
    }

    public function testWithoutALoginCheckTheUserIsNobody(): void
    {
        $this->asked = [];
        $this->config = new Config(debug: true, allow: function (?string $user, string $tool): bool {
            $this->asked[] = [$user, $tool];

            return $tool === 'refund';
        });

        // Without a login check the tools answer to this computer only.
        $local = ['Host' => 'localhost:8765'];
        self::assertSame(200, $this->serve('GET', '/refund', headers: $local)->status);
        $refused = $this->serve('GET', '/payroll', headers: $local);
        self::assertSame(403, $refused->status);
        self::assertSame(['You may not open this tool.'], $this->texts($refused, '//p[contains(@class,"sui-note")]'));
        self::assertSame([[null, 'refund'], [null, 'payroll']], $this->asked);
    }

    // --------------------------------------------------- a script on its own

    public function testAScriptOnItsOwnIsAskedAboutByItsName(): void
    {
        // A script that the web server runs by itself gets its settings from
        // configure(). Its id is its file name, like that of a tool in a folder.
        $this->with($this->rules());
        $ran = [];
        $tool = static function () use (&$ran): void {
            $ran[] = 'ran';
            title('A tool');
            if (button('Go')) {
                success('Done.');
            }
        };

        $allowed = $this->kernel()->handle(new Request('GET', '/refund.php'), 'refund', $tool);
        self::assertSame(200, $allowed->status);
        self::assertSame(['ran'], $ran);

        $refused = $this->kernel()->handle(new Request('GET', '/payroll.php'), 'payroll', $tool);
        self::assertSame(403, $refused->status);
        self::assertSame(['Not allowed'], $this->texts($refused, '//h1'));
        self::assertSame(['You may not open this tool.'], $this->texts($refused, '//p[contains(@class,"sui-note")]'));
        // There is no list to go back to.
        self::assertSame(0, $this->hits($refused, '//a[contains(@class,"sui-link")]'));
        self::assertSame(0, $this->hits($refused, '//form'));

        // Nothing of it runs, whatever the request is.
        $pressed = $this->kernel()->handle(
            new Request('POST', '/payroll.php', [], ['_click' => 'go', '_token' => $this->token()]),
            'payroll',
            $tool,
        );
        self::assertSame(403, $pressed->status);
        self::assertSame(403, $this->kernel()->handle(new Request('POST', '/payroll.php', [], ['_click' => 'go']), 'payroll', $tool)->status);

        self::assertSame(['ran'], $ran);
        self::assertSame([], $this->logged);
        self::assertSame([['ava', 'refund'], ['ava', 'payroll'], ['ava', 'payroll'], ['ava', 'payroll']], $this->asked);

        // Where the script belongs to a list, the page leads back to it.
        $listed = $this->kernel()->handle(new Request('GET', '/payroll.php'), 'payroll', $tool, homeUrl: '/tools');
        self::assertSame('/tools', $this->attr($listed, '//a[contains(@class,"sui-link")]', 'href'));

        // The check gets what the login check returned here as well, not the name.
        $member = new \stdClass();
        $got = null;
        $this->with(
            static function (mixed $user) use (&$got): bool {
                $got = $user;

                return false;
            },
            static fn (): object => $member,
        );
        self::assertSame(403, $this->kernel()->handle(new Request('GET', '/refund.php'), 'refund', $tool)->status);
        self::assertSame($member, $got);
    }

    public function testAScriptOnItsOwnIsAskedAboutUnderTheNameAFolderWouldUse(): void
    {
        $log = $this->captureErrorLog();
        // A rule that closes one tool and leaves the rest open.
        $this->with(function (?string $user, string $tool): bool {
            $this->asked[] = [$user, $tool];

            return $tool !== 'my_report';
        });
        $ran = 0;
        $tool = static function () use (&$ran): void {
            $ran++;
        };
        foreach (['my_report.php', 'Other_Report.php', 'report.v2.php', 'fine.php'] as $name) {
            touch($this->dir . '/' . $name);
        }
        // The page of a script goes by a name made for it ("my-report"). The web
        // server says which file it runs, and the question is about that file.
        $open = fn (string $file): Response => $this->kernel()->handle(
            new Request('GET', '/' . basename($file)),
            Keys::slug(pathinfo($file, PATHINFO_FILENAME)),
            $tool,
            file: $this->dir . '/' . $file,
        );

        // In a folder my_report.php is the tool "my_report". On its own it is
        // asked about under the same name, so one rule holds for both.
        self::assertSame(403, $open('my_report.php')->status);
        self::assertSame([['ava', 'my_report']], $this->asked);

        // A name that no tool of a folder can have is a name that no rule knows.
        // Such a script stays closed, and nobody is asked. The same goes for a
        // "file" that is none: a web server may hand over more of the address
        // than the name of the script.
        foreach (['Other_Report.php', 'report.v2.php', 'fine.php/my_report', 'missing.php'] as $file) {
            $response = $open($file);

            self::assertSame(403, $response->status, $file);
            self::assertSame(['You may not open this tool.'], $this->texts($response, '//p[contains(@class,"sui-note")]'), $file);
        }
        self::assertSame([['ava', 'my_report']], $this->asked);
        self::assertSame(0, $ran);

        // Whoever looks after the server reads why.
        $said = (string) file_get_contents($log);
        self::assertStringContainsString('"Other_Report.php"', $said);
        self::assertStringContainsString('"report.v2.php"', $said);
        self::assertStringContainsString('lower-case letters, digits, "-" and "_"', $said);
        self::assertSame(4, substr_count($said, 'Script UI: the script'));

        self::assertSame(200, $open('fine.php')->status);
        self::assertSame(1, $ran);
        self::assertSame([['ava', 'my_report'], ['ava', 'fine']], $this->asked);
    }

    public function testAToolThatIsAFunctionNeedsAnIdThatARuleCanName(): void
    {
        // Not a file at all: the id is what the check is asked about, and it
        // has to be one that a tool of a folder could have.
        $log = $this->captureErrorLog();
        $this->with($this->rules());
        $ran = false;
        $tool = static function () use (&$ran): void {
            $ran = true;
        };

        self::assertSame(403, $this->kernel()->handle(new Request('GET', '/x'), 'Refund Orders', $tool)->status);
        self::assertSame([], $this->asked);
        self::assertFalse($ran);
        // Closed for everybody, and whoever looks after the server reads why.
        $said = (string) file_get_contents($log);
        self::assertStringContainsString('Script UI: the tool "Refund Orders" was not opened.', $said);
        self::assertStringContainsString('lower-case letters, digits, "-" and "_"', $said);

        self::assertSame(200, $this->kernel()->handle(new Request('GET', '/x'), 'refund', $tool)->status);
        self::assertSame([['ava', 'refund']], $this->asked);
    }

    public function testWithoutAnAccessCheckAScriptOnItsOwnMayHaveAnyName(): void
    {
        $this->with(null);
        touch($this->dir . '/Other_Report.php');

        $response = $this->kernel()->handle(
            new Request('GET', '/Other_Report.php'),
            'other-report',
            static function (): void {
                title('Other report');
            },
            file: $this->dir . '/Other_Report.php',
        );

        self::assertSame(200, $response->status);
    }

    public function testAScriptOnItsOwnStaysClosedWhenTheCheckBreaks(): void
    {
        $log = $this->captureErrorLog();
        $ran = false;
        $tool = static function () use (&$ran): void {
            $ran = true;
        };

        $this->with(static fn (): int => 1);
        $response = $this->kernel()->handle(new Request('GET', '/refund.php'), 'refund', $tool);
        self::assertSame(500, $response->status);
        self::assertSame(['The access check failed'], $this->texts($response, '//h1'));

        // The login check still comes first.
        $this->with(static fn (): bool => true, static fn (): ?string => null);
        $response = $this->kernel()->handle(new Request('GET', '/refund.php'), 'refund', $tool);
        self::assertSame(403, $response->status);
        self::assertSame(['You do not have access to this page.'], $this->texts($response, '//p[contains(@class,"sui-note")]'));

        self::assertFalse($ran);
        self::assertStringContainsString('For the tool "refund" it answered 1 (int).', (string) file_get_contents($log));
    }

    // ------------------------------------------------- a check that goes wrong

    public function testOnlyAPlainYesOpensATool(): void
    {
        $log = $this->captureErrorLog();

        $answers = [
            'the number 1' => 1,
            'a text' => 'yes',
            'nothing' => null,
            'an object' => new \stdClass(),
            'a list' => ['refund'],
            'the number 0' => 0,
            'a decimal' => 0.5,
        ];

        foreach ($answers as $case => $answer) {
            $this->with(static fn (): mixed => $answer);

            foreach (['/refund', '/'] as $path) {
                $response = $this->serve('GET', $path);

                // A check that answers anything but yes or no is a mistake, and a
                // mistake must never open a tool. It does not quietly close one either.
                self::assertSame(500, $response->status, $case . ' ' . $path);
                self::assertSame(['The access check failed'], $this->texts($response, '//h1'), $case);
                self::assertSame(0, $this->hits($response, '//a[contains(@class,"sui-card")] | //form'), $case);
            }
        }

        self::assertSame([], $this->ran());
        // Whoever reads the log sees what came back: preg_match() answers 1, and a
        // look into an array of roles may answer a text.
        $said = (string) file_get_contents($log);
        self::assertStringContainsString('the access check has to answer true or false', $said);
        self::assertStringContainsString('For the tool "refund" it answered 1 (int).', $said);
        self::assertStringContainsString('it answered "yes" (string).', $said);
        self::assertStringContainsString('it answered null.', $said);
        self::assertStringContainsString('it answered a value of the type stdClass.', $said);
        self::assertStringContainsString('it answered a value of the type array.', $said);
        self::assertStringContainsString('it answered 0 (int).', $said);
        self::assertStringContainsString('it answered 0.5 (float).', $said);
    }

    public function testALongAnswerIsCutInTheLog(): void
    {
        $log = $this->captureErrorLog();
        $this->with(static fn (): string => str_repeat('a', 500) . "\nsecond line");

        self::assertSame(500, $this->serve('GET', '/refund')->status);

        $said = (string) file_get_contents($log);
        self::assertStringContainsString('it answered "' . str_repeat('a', 60) . '…" (string).', $said);
        self::assertStringNotContainsString('second line', $said);
    }

    public function testATextThatWasAnsweredCannotWriteLinesOfItsOwnIntoTheLog(): void
    {
        $log = $this->captureErrorLog();

        // The answer may come from a database or from what somebody typed. It
        // goes into the log as one line, without anything a terminal would act on.
        $this->with(static fn (): string => "yes\r\n[28-Nov-2026] Script UI: a line of its own\x00\x1B[31m");
        self::assertSame(500, $this->serve('GET', '/refund')->status);

        $said = trim((string) file_get_contents($log));
        self::assertStringContainsString('it answered "yes [28-Nov-2026] Script UI: a line of its own [31m" (string). Nobody is let in.', $said);
        self::assertCount(1, explode("\n", $said));

        // Text in another encoding than UTF-8 does not break the line either.
        $this->with(static fn (): string => "caf\xE9");
        self::assertSame(500, $this->serve('GET', '/refund')->status);

        $said = (string) file_get_contents($log);
        self::assertStringContainsString('it answered "caf?" (string).', $said);
        self::assertTrue(mb_check_encoding($said, 'UTF-8'));
    }

    public function testACheckThatBreaksLetsNobodyIn(): void
    {
        $log = $this->captureErrorLog();
        $this->with(static function (?string $user, string $tool): bool {
            throw new \RuntimeException('the permission table is gone');
        });

        foreach (['/', '/refund'] as $path) {
            $response = $this->serve('GET', $path);

            self::assertSame(500, $response->status, $path);
            self::assertSame(['The access check failed'], $this->texts($response, '//h1'), $path);
            self::assertSame(
                ['Nobody is let in until it works again. The details are in the error log of the server.'],
                $this->texts($response, '//p[contains(@class,"sui-note")]'),
                $path,
            );
            // Why it failed is for whoever runs the server, never for the page.
            self::assertStringNotContainsString('permission table', $response->body, $path);
        }

        self::assertSame([], $this->ran());
        self::assertStringContainsString('the permission table is gone', (string) file_get_contents($log));
    }

    public function testWhateverTheCheckPrintsIsThrownAway(): void
    {
        $this->with(static function (?string $user, string $tool): bool {
            echo '<script>alert(1)</script> printed by the check';

            return $tool !== 'payroll';
        });
        $this->expectOutputString('');

        foreach (['/', '/refund', '/payroll'] as $path) {
            $response = $this->serve('GET', $path);

            self::assertStringNotContainsString('printed by the check', $response->body, $path);
            self::assertStringStartsWith('<!doctype html>', strtolower(substr($response->body, 0, 15)), $path);
        }
    }

    // --------------------------------------------------------------- settings

    public function testServeAddsTheCheckToTheSettingsThatAreThere(): void
    {
        $rule = static fn (mixed $user, string $tool): bool => $tool === 'refund';

        // serve() adds its settings to those of configure(): Runtime::serve() does it with with().
        $added = (new Config(name: 'Back office'))->with(allow: $rule);
        self::assertNotNull($added->allow);
        self::assertTrue(($added->allow)(null, 'refund'));
        self::assertFalse(($added->allow)(null, 'payroll'));
        self::assertSame('Back office', $added->name);

        // It stays when another setting is added later, and nothing invents one.
        $kept = $added->with(name: 'Other');
        self::assertNotNull($kept->allow);
        self::assertFalse(($kept->allow)(null, 'payroll'));
        self::assertNull((new Config())->with(name: 'Other')->allow);
    }

    // -------------------------------------------------------------- languages

    public function testThePagesThatTurnSomebodyAwayFollowTheLanguage(): void
    {
        $this->with($this->rules(), language: 'de');
        $refused = $this->serve('GET', '/payroll');
        self::assertSame(['Nicht erlaubt'], $this->texts($refused, '//h1'));
        self::assertSame(['Sie dürfen dieses Werkzeug nicht öffnen.'], $this->texts($refused, '//p[contains(@class,"sui-note")]'));

        $this->with(static fn (): bool => false, language: 'ar');
        $empty = $this->serve('GET', '/');
        self::assertSame(['لا توجد هنا أداة يُسمح لك بفتحها.'], $this->texts($empty, '//div[contains(@class,"sui-callout")]'));
        self::assertSame('rtl', $this->attr($empty, '//html', 'dir'));
    }
}
