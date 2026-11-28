<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A tool that ends PHP with exit. Nothing can catch that, and a test that ran
 * such a tool would end with it. So each case runs in a PHP of its own, and the
 * test reads what that one printed.
 *
 * The session in these scripts does what PHP's own session does in an
 * application built on Symfony: it is closed first thing when PHP shuts down,
 * and what is put into it after that is lost.
 */
final class ExitTest extends TestCase
{
    private const HEAD = <<<'PHP'
        <?php
        require $argv[1];

        use Runio\ScriptUi\Config;
        use Runio\ScriptUi\Http\Request;
        use Runio\ScriptUi\Kernel;
        use Runio\ScriptUi\Session\FrameworkSession;

        use function Runio\ScriptUi\{title, text, note, button};

        final class AppSession
        {
            public array $data = [];
            public bool $open = true;

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->data[$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function save(): void
            {
                $kept = $this->data['_script_ui'] ?? [];
                echo $this->open ? '[saved ' . implode(',', array_keys($kept)) . ' ' . json_encode($kept['state'] ?? null) . ']' : '[saved too late]';
            }
        }

        $session = new AppSession();
        register_shutdown_function(static function () use ($session): void {
            $session->open = false;
            echo '[the shutdown of PHP begins]';
        });
        $kernel = static fn (): Kernel => new Kernel(new Config(auth: static fn (): string => 'ava', debug: true), new FrameworkSession($session));

        PHP;

    private string $script;

    protected function setUp(): void
    {
        $this->script = sys_get_temp_dir() . '/script-ui-exit-' . bin2hex(random_bytes(6)) . '.php';
    }

    protected function tearDown(): void
    {
        if (is_file($this->script)) {
            unlink($this->script);
        }
    }

    /** What a PHP of its own prints when it runs the script. */
    private function printedBy(string $body): string
    {
        file_put_contents($this->script, self::HEAD . $body);

        $command = escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1 '
            . escapeshellarg($this->script) . ' ' . escapeshellarg(dirname(__DIR__) . '/vendor/autoload.php') . ' 2>&1';
        exec($command, $output, $status);

        self::assertSame(0, $status, implode("\n", $output));

        return implode("\n", $output);
    }

    public function testThePageOfAToolThatEndsPhpIsSentBeforePhpShutsDown(): void
    {
        $printed = $this->printedBy(<<<'PHP'
            echo '[before]';
            $kernel()->handle(new Request('GET', '/leave'), 'leave', static function (): void {
                title('Leave early');
                note('Drawn before the end.');
                echo 'Printed before the end.';
                exit;
            })->send();
            echo '[never reached]';
            PHP);

        self::assertStringStartsWith('[before][saved csrf,secret null]<!doctype html>', $printed);
        self::assertStringContainsString('Drawn before the end.', $printed);
        // What the tool printed is on the page, and not in front of it.
        self::assertSame(1, preg_match('~<body.*Printed before the end\.~s', $printed));
        self::assertMatchesRegularExpression('~</html>\s*\[the shutdown of PHP begins\]$~', $printed);
        // Once, and nothing of what stood behind the tool ran.
        self::assertSame(1, substr_count($printed, '<!doctype html>'));
        self::assertStringNotContainsString('[never reached]', $printed);
        self::assertStringNotContainsString('too late', $printed);
    }

    public function testWhatWasTypedIsKeptInTimeForASessionThatClosesFirst(): void
    {
        $printed = $this->printedBy(<<<'PHP'
            $tool = static function (): void {
                title('Leave early');
                $word = text('Word');
                note('The word is "' . $word . '".');
                exit;
            };
            // A page view first, for the token of the form.
            $first = $kernel()->handle(new Request('GET', '/leave'), 'leave', static function (): void {
                title('Leave early');
                text('Word');
            })->body;
            preg_match('/name="_token" value="([0-9a-f]+)"/', $first, $token);

            echo '[typing]';
            $kernel()->handle(new Request('POST', '/leave', [], ['_token' => $token[1], 'word' => 'lantern']), 'leave', $tool)->send();
            PHP);

        self::assertStringContainsString('[typing][saved csrf,secret,state {"leave":{"word":"lantern"}}]<!doctype html>', $printed);
        self::assertStringContainsString('The word is &quot;lantern&quot;.', $printed);
        self::assertStringNotContainsString('too late', $printed);
    }

    public function testOnlyThePageThatWasBeingDrawnAnswers(): void
    {
        // A tool that draws another tool, which ends PHP: not a normal thing to
        // do. One page leaves, and not one inside the other.
        $printed = $this->printedBy(<<<'PHP'
            $kernel()->handle(new Request('GET', '/outer'), 'outer', static function () use ($kernel): void {
                title('Outer');
                $kernel()->handle(new Request('GET', '/inner'), 'inner', static function (): void {
                    title('Inner');
                    exit;
                });
            })->send();
            PHP);

        self::assertSame(1, substr_count($printed, '<!doctype html>'));
        self::assertSame(1, substr_count($printed, '[saved '));
        self::assertMatchesRegularExpression('~<h1[^>]*>Inner</h1>~', $printed);
        self::assertDoesNotMatchRegularExpression('~<h1[^>]*>Outer</h1>~', $printed);
    }

    public function testAToolThatEndsInTheNormalWayIsNotTakenForOneThatLeft(): void
    {
        $printed = $this->printedBy(<<<'PHP'
            $response = $kernel()->handle(new Request('GET', '/stay'), 'stay', static function (): void {
                title('Stay');
            });
            echo '[handed back ' . $response->status . ']';
            $failed = $kernel()->handle(new Request('GET', '/fail'), 'fail', static function (): void {
                title('Fail');
                throw new RuntimeException('broken on purpose');
            });
            echo '[handed back ' . $failed->status . ']';
            PHP);

        // Nothing was sent and nothing was saved: the caller decides about both.
        self::assertSame('[handed back 200][handed back 500][the shutdown of PHP begins]', $printed);
    }

    public function testAPageThatCannotBeSentGoesToTheLog(): void
    {
        // PHP is ending, and nobody is left to catch an error. It is written
        // down in one line, and not thrown into the shutdown.
        $printed = $this->printedBy(<<<'PHP'
            ini_set('error_log', $argv[0] . '.log');
            $fails = new class () {
                public array $data = [];

                public function get(string $key, mixed $default = null): mixed
                {
                    return $this->data[$key] ?? $default;
                }

                public function set(string $key, mixed $value): void
                {
                    if (isset($value['state'])) {
                        throw new RuntimeException("the session store is gone\nsecond line");
                    }
                    $this->data[$key] = $value;
                }
            };
            register_shutdown_function(static function () use ($argv): void {
                echo '[log: ' . trim((string) @file_get_contents($argv[0] . '.log')) . ']';
                @unlink($argv[0] . '.log');
            });
            $kernel = static fn (): Kernel => new Kernel(new Config(auth: static fn (): string => 'ava', debug: true), new FrameworkSession($fails));
            $tool = static function (): void {
                title('Leave early');
                text('Word');
                exit;
            };
            $first = $kernel()->handle(new Request('GET', '/leave'), 'leave', static function (): void {
                title('Leave early');
                text('Word');
            })->body;
            preg_match('/name="_token" value="([0-9a-f]+)"/', $first, $token);

            $kernel()->handle(new Request('POST', '/leave', [], ['_token' => $token[1], 'word' => 'lantern']), 'leave', $tool);
            PHP);

        self::assertStringContainsString('Script UI: the page of a tool that ended PHP could not be sent: the session store is gone second line]', $printed);
        self::assertStringNotContainsString('Fatal error', $printed);
        self::assertStringNotContainsString('Uncaught', $printed);
    }
}
