<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Config;
use Runio\ScriptUi\Http\Request;
use Runio\ScriptUi\Kernel;
use Runio\ScriptUi\Runtime;
use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\configure;
use function Runio\ScriptUi\note;
use function Runio\ScriptUi\respond;
use function Runio\ScriptUi\serve;
use function Runio\ScriptUi\title;
use function Runio\ScriptUi\user;

final class RuntimeTest extends ToolTestCase
{
    protected function tearDown(): void
    {
        Runtime::reset();

        parent::tearDown();
    }

    /** A kernel with the settings configure() made, plus a login check that lets the test in. */
    private function kernelOf(Config $config): Kernel
    {
        return new Kernel($config->with(auth: static fn (): string => 'tester', debug: true), $this->session);
    }

    public function testUiFunctionsNeedAWebRequest(): void
    {
        // On the command line there is no page to draw on.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('browser');

        title('Hello');
    }

    public function testServeOnTheCommandLineSaysHowToStartAServer(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Start a server with "php vendor/bin/script-ui serve <folder>"');

        serve(__DIR__);
    }

    public function testRespondOnTheCommandLineSaysWhyThereIsNoRequest(): void
    {
        // This is where the test client of Symfony or Laravel ends up. Starting
        // a server, the advice of serve(), would tell its user nothing.
        try {
            respond(__DIR__);
            self::fail('respond() answered on the command line.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('respond() takes the request from PHP itself', $error->getMessage());
            self::assertStringContainsString('The test client of a framework runs there too and does not fill them in.', $error->getMessage());
            self::assertStringContainsString('with a browser test', $error->getMessage());
            self::assertStringNotContainsString('script-ui serve', $error->getMessage());
        }
    }

    public function testRespondRefusesWhatIsNoSessionBeforeAnythingElse(): void
    {
        // A mistake in the code is named first, also where there is no request.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('session: has to be the session of your application');

        respond(__DIR__, session: new \stdClass());
    }

    public function testRespondChecksTheSettingsLikeServe(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"this" belongs to PHP');

        respond(__DIR__, variables: ['this' => 1]);
    }

    public function testNeitherIsCalledFromInsideATool(): void
    {
        $served = $this->get(function (): void {
            note('before');
            serve(__DIR__);
        });
        self::assertSame(500, $served->status);
        self::assertStringContainsString('serve() cannot be called from inside a tool.', $served->body);

        $responded = $this->get(function (): void {
            note('before');
            respond(__DIR__);
        });
        self::assertSame(500, $responded->status);
        self::assertStringContainsString('respond() cannot be called from inside a tool.', $responded->body);
    }

    public function testConfigureSetsTheDefaults(): void
    {
        self::assertNull(Runtime::config()->auth);
        self::assertNull(Runtime::config()->name);
        self::assertNull(Runtime::config()->language);

        configure(auth: static fn (): string => 'ava', name: 'Back office');
        configure(debug: false);

        $config = Runtime::config();
        self::assertNotNull($config->auth);
        self::assertSame('ava', ($config->auth)());
        self::assertSame('Back office', $config->name);
        self::assertFalse($config->debug);
    }

    public function testConfigureTakesTheAccessCheckToo(): void
    {
        self::assertNull(Runtime::config()->allow);

        // In a file that every script includes: one rule for all of them.
        configure(allow: static fn (mixed $user, string $tool): bool => $tool === 'refund');
        configure(name: 'Back office');

        $kernel = $this->kernelOf(Runtime::config());
        $tool = static function (): void {
            title('A tool');
        };

        self::assertSame(200, $kernel->handle(new Request('GET', '/refund.php'), 'refund', $tool)->status);
        self::assertSame(403, $kernel->handle(new Request('GET', '/payroll.php'), 'payroll', $tool)->status);
    }

    public function testConfigureSetsTheLanguageAndOwnTexts(): void
    {
        configure(language: 'de');
        configure(texts: ['de' => ['page.reset' => 'Leeren']]);

        $response = $this->kernelOf(Runtime::config())->handle(new Request('GET', '/tool'), 'tool', static function (): void {
            title('Bestellungen');
        });

        self::assertSame('de', $this->attr($response, '/html', 'lang'));
        self::assertSame(['Leeren'], $this->texts($response, '//a[contains(@class,"sui-reset")]'));
    }

    public function testConfigureRefusesALanguageItHasNoTextsFor(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        configure(language: 'fr');
    }

    public function testConfigureIsRefusedOnceThePageHasStarted(): void
    {
        // Otherwise a login check added here would silently not apply.
        $response = $this->get(function (): void {
            note('before');
            configure(auth: static fn (): ?string => null);
        });

        self::assertSame(500, $response->status);
        self::assertStringContainsString('configure()', $response->body);
        self::assertNull(Runtime::config()->auth);
    }

    public function testTheUserIsKnownInsideATool(): void
    {
        $this->config = new Config(auth: static fn (): string => 'ava', debug: true);

        $who = null;
        $this->get(function () use (&$who): void {
            $who = user();
        });

        self::assertSame('ava', $who);
    }

    public function testNestedPagesDoNotLeakIntoEachOther(): void
    {
        // A tool that renders another tool (not a normal thing to do) must get its own page back.
        $response = $this->get(function (): void {
            note('outer before');
            $this->get(function (): void {
                note('inner');
            });
            note('outer after');
        });

        self::assertSame(['outer before', 'outer after'], $this->texts($response, '//p[contains(@class,"sui-note")]'));
    }
}
