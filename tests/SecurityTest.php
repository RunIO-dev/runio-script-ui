<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Runio\ScriptUi\Config;
use Runio\ScriptUi\Http\Request;
use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\chart;
use function Runio\ScriptUi\checkbox;
use function Runio\ScriptUi\code;
use function Runio\ScriptUi\columns;
use function Runio\ScriptUi\date_input;
use function Runio\ScriptUi\divider;
use function Runio\ScriptUi\error;
use function Runio\ScriptUi\heading;
use function Runio\ScriptUi\info;
use function Runio\ScriptUi\json;
use function Runio\ScriptUi\link;
use function Runio\ScriptUi\metric;
use function Runio\ScriptUi\note;
use function Runio\ScriptUi\number;
use function Runio\ScriptUi\rerun;
use function Runio\ScriptUi\select;
use function Runio\ScriptUi\success;
use function Runio\ScriptUi\table;
use function Runio\ScriptUi\text;
use function Runio\ScriptUi\textarea;
use function Runio\ScriptUi\title;
use function Runio\ScriptUi\user;
use function Runio\ScriptUi\warning;

final class SecurityTest extends ToolTestCase
{
    public function testAFormWithoutTheTokenIsRejected(): void
    {
        $ran = false;
        $tool = function () use (&$ran): void {
            $ran = true;
        };
        $this->token(); // a session with a token exists
        $ran = false;

        foreach ([[], ['_token' => str_repeat('0', 64)], ['_token' => ['array']], ['_token' => '']] as $fields) {
            $response = $this->kernel()->handle(
                new Request('POST', '/tool', [], $fields + ['_click' => 'delete']),
                'tool',
                $tool,
            );

            self::assertSame(419, $response->status);
            self::assertFalse($ran);
        }
    }

    public function testACrossSiteFormIsRejectedEvenWithTheToken(): void
    {
        $ran = false;
        $tool = function () use (&$ran): void {
            $ran = true;
        };

        $response = $this->post($tool, [], ['sec-fetch-site' => 'cross-site']);
        self::assertSame(419, $response->status);
        self::assertFalse($ran);

        $response = $this->post($tool, [], ['sec-fetch-site' => 'same-origin']);
        self::assertSame(200, $response->status);
        self::assertTrue($ran);
    }

    public function testTheTokenStaysTheSameForTheSession(): void
    {
        $first = $this->token();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);

        $response = $this->get(static function (): void {
        });

        self::assertSame($first, $this->token());
        self::assertSame($first, $this->attr($response, '//form[@id="sui-form"]//input[@name="_token"]', 'value'));
    }

    public function testEveryPageSendsProtectiveHeaders(): void
    {
        $tool = static function (): void {
            title('Hello');
        };
        $response = $this->get($tool);

        $policy = (string) $response->header('Content-Security-Policy');
        self::assertSame(1, preg_match("~script-src 'nonce-([A-Za-z0-9+/=]+)'~", $policy, $match));
        self::assertStringContainsString("style-src 'nonce-" . $match[1] . "'", $policy);
        self::assertSame($match[1], $this->attr($response, '//script', 'nonce'));
        self::assertSame($match[1], $this->attr($response, '//style', 'nonce'));
        self::assertStringNotContainsString('unsafe-inline', $policy);
        self::assertStringContainsString("default-src 'none'", $policy);
        self::assertStringContainsString("form-action 'self'", $policy);
        self::assertStringContainsString("frame-ancestors 'none'", $policy);
        self::assertStringContainsString("base-uri 'none'", $policy);

        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('DENY', $response->header('X-Frame-Options'));
        self::assertSame('same-origin', $response->header('Referrer-Policy'));
        self::assertSame('no-store', $response->header('Cache-Control'));

        self::assertNotSame($policy, $this->get($tool)->header('Content-Security-Policy'), 'a new nonce per page');
    }

    public function testThePageNeedsNoInlineStylesOrHandlers(): void
    {
        $rows = [['d' => 'Mon', 'v' => 1, 'w' => 4], ['d' => 'Tue', 'v' => 3, 'w' => -2]];

        $response = $this->post(function () use ($rows): void {
            title('Everything');
            heading('Section');
            text('Name', help: 'Your name');
            number('Quantity', min: 1, max: 9);
            select('Pick', ['a', 'b']);
            checkbox('Enabled');
            date_input('Day');
            textarea('Notes');
            metric('Revenue', '$10', '+4%');
            table($rows, selectable: true);
            chart($rows, x: 'd', y: 'v');
            chart($rows, x: 'd', y: ['v', 'w'], type: 'line', title: 'Trend');
            columns(static fn () => note('left'), static fn () => note('right'));
            if (button('Go', confirm: 'Sure?')) {
                note('done');
            }
            success('ok');
            info('fyi');
            warning('careful');
            error('bad');
            link('Docs', 'https://example.com/docs');
            code('x');
            json(['a' => 1]);
            divider();
        }, ['_click' => 'go']);

        self::assertSame(200, $response->status);
        self::assertSame(0, $this->hits($response, '//@style'));
        self::assertSame(0, $this->hits($response, '//@*[starts-with(name(), "on")]'));
        self::assertSame(1, $this->hits($response, '//script'));
        self::assertSame(1, $this->hits($response, '//style'));
        self::assertSame(0, $this->hits($response, '//script[not(@nonce)] | //style[not(@nonce)]'));
        self::assertSame(0, $this->hits($response, '//script[@src] | //link[@rel="stylesheet"] | //img | //iframe'));
    }

    public function testEverythingADeveloperPassesInIsEscaped(): void
    {
        $evil = '<img src=x onerror=alert(1)>';

        $response = $this->post(function () use ($evil): void {
            title($evil);
            heading($evil);
            note($evil);
            // One label for everything: each input then needs a key of its own.
            text($evil, default: $evil, placeholder: $evil, help: $evil);
            textarea($evil, default: $evil, key: 'area');
            select($evil, [$evil], key: 'choice');
            checkbox($evil, key: 'box');
            metric($evil, $evil, $evil);
            success($evil);
            info($evil);
            warning($evil);
            error($evil);
            code($evil);
            json([$evil => $evil]);
            table([[$evil => $evil, 'id' => $evil]], selectable: true);
            chart([['x' => $evil, 'y' => 1]], x: 'x', y: ['y' => $evil], title: $evil);
            button($evil, key: 'plain');
            button($evil, confirm: $evil, yes: $evil, no: $evil, key: 'ask');
            link($evil, '/docs');
            echo $evil;
        }, ['_click' => 'ask']);

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<img', $response->body);
        self::assertSame(0, $this->hits($response, '//img'));
    }

    public function testLinksOnlyAllowWebAddresses(): void
    {
        $response = $this->get(function (): void {
            link('Docs', 'https://example.com/a?b=1&c=2');
            link('Order', '/admin/orders/5');
            link('Mail', 'mailto:ava@example.com');
        });

        self::assertSame(
            ['https://example.com/a?b=1&c=2', '/admin/orders/5', 'mailto:ava@example.com'],
            $this->texts($response, '//a[contains(@class,"sui-link")]/@href'),
        );

        $refused = [
            'javascript:alert(1)',
            ' javascript:alert(1)',
            "java\tscript:alert(1)",
            'JaVaScRiPt:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'vbscript:msgbox(1)',
        ];

        foreach ($refused as $url) {
            $response = $this->get(function () use ($url): void {
                link('Click me', $url);
            });

            self::assertSame(500, $response->status, $url);
            self::assertSame(0, $this->hits($response, '//a[contains(@class,"sui-link")]'), $url);
        }
    }

    public function testTheLoginCheckDecidesWhoGetsIn(): void
    {
        $this->config = new Config(
            auth: static fn (Request $request): ?string => $request->header('x-user'),
            debug: true,
        );

        $ran = false;
        $who = 'unset';
        $tool = function () use (&$ran, &$who): void {
            $ran = true;
            $who = user();
        };

        $response = $this->get($tool);
        self::assertSame(403, $response->status);
        self::assertFalse($ran);
        self::assertSame(0, $this->hits($response, '//form'));

        $response = $this->get($tool, ['x-user' => 'ava']);
        self::assertSame(200, $response->status);
        self::assertSame('ava', $who);
        self::assertSame(['ava'], $this->texts($response, '//*[contains(@class,"sui-user")]'));
    }

    public function testALoginCheckThatBreaksKeepsEveryoneOut(): void
    {
        $log = $this->captureErrorLog();
        $this->config = new Config(
            auth: static function (): string {
                throw new \RuntimeException('session store is down');
            },
            debug: true,
        );

        $ran = false;
        $response = $this->get(function () use (&$ran): void {
            $ran = true;
        });

        self::assertSame(500, $response->status);
        self::assertFalse($ran);
        self::assertStringNotContainsString('session store is down', $response->body);
        self::assertStringContainsString('session store is down', (string) file_get_contents($log));
    }

    public function testWithoutALoginCheckOnlyThisMachineGetsIn(): void
    {
        $this->config = new Config(debug: true);

        $ran = 0;
        $tool = function () use (&$ran): void {
            $ran++;
        };
        $local = ['host' => 'localhost:8765'];
        $kernel = $this->kernel();

        $here = $kernel->handle(new Request('GET', '/tool', [], [], $local, '127.0.0.1'), 'tool', $tool);
        self::assertSame(200, $here->status);

        $remote = $kernel->handle(new Request('GET', '/tool', [], [], ['host' => 'tools.example.com'], '203.0.113.9'), 'tool', $tool);
        self::assertSame(403, $remote->status);
        self::assertStringContainsString('auth', $remote->body, 'the page says how to open it up');

        $proxied = $kernel->handle(new Request('GET', '/tool', [], [], $local + ['x-forwarded-for' => '203.0.113.9'], '127.0.0.1'), 'tool', $tool);
        self::assertSame(403, $proxied->status);

        $rebound = $kernel->handle(new Request('GET', '/tool', [], [], ['host' => 'evil.example.com'], '127.0.0.1'), 'tool', $tool);
        self::assertSame(403, $rebound->status);

        self::assertSame(1, $ran);
    }

    public function testALinkCanFillInFields(): void
    {
        $email = null;
        $active = null;
        $tool = function () use (&$email, &$active): void {
            $email = text('Customer email');
            $active = checkbox('Active', default: true);
        };

        $response = $this->get($tool, query: ['customer-email' => 'ava@example.com', 'active' => '0']);
        self::assertSame('ava@example.com', $email);
        self::assertFalse($active);
        self::assertSame('/tool', $this->attr($response, '//form[@id="sui-form"]', 'action'), 'the form posts to the clean address');

        $this->get($tool);
        self::assertSame('', $email, 'opening a link does not change what is saved');
        self::assertTrue($active);
    }

    public function testALinkCannotTouchInternalFields(): void
    {
        $picked = null;
        $pressed = null;
        $this->get(function () use (&$picked, &$pressed): void {
            $picked = table([['id' => 1]], selectable: true);
            $pressed = button('Delete');
        }, query: ['_sel' => ['table' => ['1']], '_click' => 'delete', '_armed' => 'delete', '_seen' => ['x']]);

        self::assertSame([], $picked);
        self::assertFalse($pressed);
    }

    public function testResetForgetsTheSavedInputs(): void
    {
        $name = null;
        $runs = 0;
        $tool = function () use (&$name, &$runs): void {
            $runs++;
            $name = text('Name', default: 'nobody');
        };

        $this->post($tool, ['name' => 'Ava']);
        $this->get($tool);
        self::assertSame('Ava', $name);
        $runs = 0;

        // The link in the header of the page carries a code that only this session can make.
        $query = $this->resetQuery($this->get($tool));
        self::assertMatchesRegularExpression('/^[0-9a-f]{24}$/', (string) ($query['_reset'] ?? ''));
        $runs = 0;

        $response = $this->get($tool, query: $query);
        self::assertSame(303, $response->status);
        self::assertSame('/tool', $response->header('Location'));
        self::assertSame(0, $runs, 'the tool does not run while resetting');

        $this->get($tool);
        self::assertSame('nobody', $name);
    }

    public function testAResetLinkFromSomewhereElseForgetsNothing(): void
    {
        $name = null;
        $tool = function () use (&$name): void {
            $name = text('Name', default: 'nobody');
            if (button('Save')) {
                success('Saved.');
                rerun();
            }
        };

        $this->post($tool, ['name' => 'Ava', '_click' => 'save']);

        // Another site can make the browser open this address, but it cannot know the code.
        foreach (['1', '', str_repeat('0', 24), ['array']] as $guess) {
            $response = $this->get($tool, query: ['_reset' => $guess]);
            self::assertSame(303, $response->status);
            self::assertSame('/tool', $response->header('Location'));
        }

        $response = $this->get($tool);
        self::assertSame('Ava', $name, 'the saved inputs are still there');
        self::assertStringContainsString('Saved.', $response->body, 'and so is the message that was waiting');
    }

    public function testWhatALinkFilledInIsNotFilledInAgainAfterEveryRun(): void
    {
        // A script that the web server runs directly keeps its own query string
        // (the application may route with it). A link can add values for fields.
        $email = null;
        $tool = function () use (&$email): void {
            $email = text('Customer email');
            if (button('Save')) {
                success('Saved.');
                rerun();
            }
        };
        $kernel = $this->kernel();
        $token = $this->token();

        $response = $kernel->handle(
            new Request('GET', '/tool.php', ['page' => 'refund', 'customer-email' => 'ava@example.com']),
            'tool',
            $tool,
            selfUrl: '/tool.php?page=refund&customer-email=ava%40example.com',
        );
        self::assertSame('ava@example.com', $email);
        // The form posts to the address without the value, so the value is not forced on every later run.
        self::assertSame('/tool.php?page=refund', $this->attr($response, '//form[@id="sui-form"]', 'action'));
        self::assertStringStartsWith('/tool.php?page=refund&_reset=', (string) $this->attr($response, '//a[contains(@class,"sui-reset")]', 'href'));

        $response = $kernel->handle(
            new Request('POST', '/tool.php', ['page' => 'refund'], ['_token' => $token, 'customer-email' => 'omar@example.com', '_click' => 'save']),
            'tool',
            $tool,
            selfUrl: '/tool.php?page=refund',
        );
        self::assertSame(303, $response->status);
        self::assertSame('/tool.php?page=refund', $response->header('Location'));

        $kernel->handle(new Request('GET', '/tool.php', ['page' => 'refund']), 'tool', $tool, selfUrl: '/tool.php?page=refund');
        self::assertSame('omar@example.com', $email, 'what the user typed is still there after the action');
    }

    public function testToolsThatShareOneAddressNeverSeeEachOthersInputs(): void
    {
        // An old-style application: index.php?page=refund and index.php?page=customers.
        $name = null;
        $tool = function () use (&$name): void {
            $name = text('Name', default: 'nobody');
        };
        $kernel = $this->kernel();
        $token = $this->token();
        $open = function (array $query) use ($kernel, $tool): void {
            $kernel->handle(new Request('GET', '/index.php', $query), 'index', $tool, selfUrl: '/index.php?' . http_build_query($query), stateKey: 'index~x');
        };

        $kernel->handle(
            new Request('POST', '/index.php', ['page' => 'refund'], ['_token' => $token, 'name' => 'Ava']),
            'index',
            $tool,
            selfUrl: '/index.php?page=refund',
            stateKey: 'index~x',
        );

        $open(['page' => 'customers']);
        self::assertSame('nobody', $name, 'another tool at the same path starts empty');

        $open(['page' => 'refund']);
        self::assertSame('Ava', $name);

        $open(['page' => 'refund', 'ref' => 'newsletter']);
        self::assertSame('Ava', $name, 'one more parameter does not make it another tool');
    }

    public function testAToolThatSendsItsOwnAnswerIsLeftAlone(): void
    {
        // For example a download: the script throws away the page and prints a file.
        $this->expectOutputString('own-answer');

        $response = $this->get(function (): void {
            note('never shown');
            ob_end_clean();
            echo 'own-answer';
        });

        self::assertSame('', $response->body, 'no page is added after what the tool sent');
        self::assertNull($response->header('Content-Type'), 'and its headers are not replaced');
    }

    public function testAMissingPageBufferAfterAnErrorIsNotAnAnswerOfTheTool(): void
    {
        // When PHP runs out of memory it throws away every output buffer before the
        // page can be finished. The page with the error still has to be sent.
        $this->expectOutputString('');

        $response = $this->get(function (): void {
            note('drawn before');
            ob_end_clean();

            throw new \RuntimeException('failed after the buffer was gone');
        });

        self::assertSame(500, $response->status);
        self::assertStringContainsString('drawn before', $response->body);
        self::assertStringContainsString('failed after the buffer was gone', $response->body);
    }

    public function testTwoToolsWithTheSameNameKeepTheirInputsApart(): void
    {
        // A script that the web server runs directly is named after its file, and
        // many files are called index.php. What they save is filed under a longer key.
        $name = null;
        $tool = function () use (&$name): void {
            $name = text('Name', default: 'nobody');
        };
        $kernel = $this->kernel();
        $token = $this->token();

        $kernel->handle(new Request('POST', '/a/index.php', [], ['_token' => $token, 'name' => 'Ava']), 'index', $tool, stateKey: 'index~a');

        $kernel->handle(new Request('GET', '/b/index.php'), 'index', $tool, stateKey: 'index~b');
        self::assertSame('nobody', $name);

        $kernel->handle(new Request('GET', '/a/index.php'), 'index', $tool, stateKey: 'index~a');
        self::assertSame('Ava', $name);
    }

    public function testASessionKeepsTheInputsOfAHandfulOfToolsAndNotOfEveryAddress(): void
    {
        // A script that the web server runs directly is filed under its path, and a
        // web server takes any path behind the script (/index.php/1, /index.php/2).
        // Somebody who is logged in could fill the session with one saved form per path.
        $name = null;
        $tool = function () use (&$name): void {
            $name = text('Name', default: 'nobody');
        };
        $kernel = $this->kernel();
        $token = $this->token();
        $save = static fn (int $n, string $as) => $kernel->handle(
            new Request('POST', '/index.php/' . $n, [], ['_token' => $token, 'name' => $as]),
            'index',
            $tool,
            stateKey: 'index~' . $n,
        );
        $open = static fn (int $n) => $kernel->handle(new Request('GET', '/index.php/' . $n), 'index', $tool, stateKey: 'index~' . $n);

        for ($n = 1; $n <= 50; $n++) {
            $save($n, 'user ' . $n);
        }
        self::assertCount(50, $this->session->get('state'));

        // The first one is used again, so it is not the one that goes.
        $save(1, 'user 1 again');
        $save(51, 'user 51');
        $save(52, 'user 52');

        $states = $this->session->get('state');
        self::assertCount(50, $states);
        self::assertSame(['index~1', 'index~51', 'index~52'], array_slice(array_keys($states), -3));

        $open(1);
        self::assertSame('user 1 again', $name);
        $open(2);
        self::assertSame('nobody', $name, 'the one that was used longest ago was forgotten');
        $open(4);
        self::assertSame('user 4', $name);
    }

    public function testAToolWithANumberForANameKeepsItsInputs(): void
    {
        // PHP turns the name "2024" into the number 2024 where it names an entry of a list.
        $name = null;
        $tool = function () use (&$name): void {
            $name = text('Name', default: 'nobody');
        };
        $kernel = $this->kernel();
        $token = $this->token();

        for ($n = 2000; $n <= 2060; $n++) {
            $kernel->handle(new Request('POST', '/' . $n, [], ['_token' => $token, 'name' => 'year ' . $n]), (string) $n, $tool);
        }

        $kernel->handle(new Request('GET', '/2060'), '2060', $tool);
        self::assertSame('year 2060', $name);
        $kernel->handle(new Request('GET', '/2011'), '2011', $tool);
        self::assertSame('year 2011', $name);
        $kernel->handle(new Request('GET', '/2000'), '2000', $tool);
        self::assertSame('nobody', $name);
    }

    public function testAVeryLongMessageIsCutBeforeItIsCarriedOver(): void
    {
        $tool = function (): void {
            if (button('Save')) {
                success(str_repeat('é', 50000));
                rerun();
            }
        };

        $this->post($tool, ['_click' => 'save']);

        $flash = $this->session->get('flash');
        self::assertIsArray($flash);
        $text = $flash['tool'][0]['text'] ?? null;
        self::assertIsString($text);
        self::assertLessThanOrEqual(4096, strlen($text));
        self::assertGreaterThan(4000, strlen($text));
        self::assertTrue(mb_check_encoding($text, 'UTF-8'), 'cut between two characters, not inside one');

        $response = $this->get($tool);
        self::assertSame(1, $this->hits($response, '//div[contains(@class,"sui-callout") and contains(@class,"sui-success")]'));
    }

    public function testWhatTheLoginCheckPrintsNeverReachesTheBrowser(): void
    {
        $this->config = new Config(auth: static function (): string {
            echo 'printed-by-the-login-check';

            return 'tester';
        }, debug: true);
        $this->expectOutputString('');

        $response = $this->get(function (): void {
            note('ok');
        });

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('printed-by-the-login-check', $response->body);
    }

    public function testWhatTheLogCallbackPrintsIsThrownAway(): void
    {
        $this->config = new Config(
            auth: static fn (): string => 'tester',
            log: static function (array $entry): void {
                echo 'printed-by-the-log';
            },
            debug: true,
        );
        $this->expectOutputString('');

        $response = $this->post(function (): void {
            button('Go');
        }, ['_click' => 'go']);

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('printed-by-the-log', $response->body);
    }

    #[WithoutErrorHandler]
    public function testWarningsOfAToolStayOutOfThePageUnlessDebugIsOn(): void
    {
        $log = $this->captureErrorLog();
        $before = [
            'display_errors' => ini_set('display_errors', '1'),
            'log_errors' => ini_set('log_errors', '1'),
            'html_errors' => ini_set('html_errors', '1'),
        ];

        try {
            // PHP prints warnings into the page when display_errors is on. They name files and lines.
            $this->config = new Config(auth: static fn (): string => 'tester', debug: false);
            $response = $this->get(function (): void {
                note('before');
                trigger_error('secret-path-in-a-warning', E_USER_WARNING);
                note('after');
            });

            self::assertSame(200, $response->status);
            self::assertStringNotContainsString('secret-path-in-a-warning', $response->body);
            self::assertSame(['before', 'after'], $this->texts($response, '//p[contains(@class,"sui-note")]'));
            self::assertStringContainsString('secret-path-in-a-warning', (string) file_get_contents($log), 'it still goes to the error log');
            self::assertSame('1', ini_get('display_errors'), 'the setting is put back after the run');

            // With debug the developer sees them, as plain text, because the page escapes what is printed.
            $this->config = new Config(auth: static fn (): string => 'tester', debug: true);
            $htmlErrors = null;
            $response = $this->get(function () use (&$htmlErrors): void {
                $htmlErrors = ini_get('html_errors');
                trigger_error('shown-to-the-developer', E_USER_WARNING);
            });

            self::assertStringContainsString(
                'shown-to-the-developer',
                implode(' ', $this->texts($response, '//pre[contains(@class,"sui-echo")]')),
            );
            self::assertSame('0', $htmlErrors);
            self::assertSame('1', ini_get('html_errors'), 'the setting is put back after the run');
        } finally {
            foreach ($before as $name => $value) {
                if ($value !== false) {
                    ini_set($name, $value);
                }
            }
        }
    }

    public function testOtherHttpMethodsAreRefused(): void
    {
        $ran = false;
        $response = $this->kernel()->handle(new Request('DELETE', '/tool'), 'tool', function () use (&$ran): void {
            $ran = true;
        });

        self::assertSame(405, $response->status);
        self::assertFalse($ran);
    }

    public function testErrorDetailsAreHiddenUnlessDebugIsOn(): void
    {
        $log = $this->captureErrorLog();
        $this->config = new Config(auth: static fn (): string => 'tester', debug: false);

        $response = $this->get(function (): void {
            throw new \RuntimeException('secret-table-name');
        });

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('secret-table-name', $response->body);
        self::assertStringContainsString('secret-table-name', (string) file_get_contents($log));
    }

    public function testErrorDetailsAreOnlyShownByDefaultWhereNobodyElseGetsIn(): void
    {
        $this->captureErrorLog();
        $tool = function (): void {
            throw new \RuntimeException('secret-table-name');
        };
        $here = new Request('GET', '/tool', [], [], ['host' => 'localhost'], '127.0.0.1');
        $remote = new Request('GET', '/tool', [], [], ['host' => 'tools.example.com'], '203.0.113.9');

        // No login check: only this computer gets in, and its user is the developer.
        $this->config = new Config();
        $response = $this->kernel()->handle($here, 'tool', $tool);
        self::assertSame(500, $response->status);
        self::assertStringContainsString('secret-table-name', $response->body);

        // A login check means other people use the tools. Whether a request "looks local"
        // is then no reason to show them file paths: a proxy in front can make every request look local.
        $this->config = new Config(auth: static fn (): string => 'tester');
        foreach ([$here, $remote] as $request) {
            $response = $this->kernel()->handle($request, 'tool', $tool);
            self::assertSame(500, $response->status);
            self::assertStringNotContainsString('secret-table-name', $response->body);
        }

        // The developer asks for the details where they are wanted.
        $this->config = new Config(auth: static fn (): string => 'tester', debug: true);
        $response = $this->kernel()->handle($remote, 'tool', $tool);
        self::assertStringContainsString('secret-table-name', $response->body);
    }
}
