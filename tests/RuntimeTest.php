<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Config;
use Runio\ScriptUi\Runtime;
use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\configure;
use function Runio\ScriptUi\note;
use function Runio\ScriptUi\title;
use function Runio\ScriptUi\user;

final class RuntimeTest extends ToolTestCase
{
    protected function tearDown(): void
    {
        Runtime::reset();

        parent::tearDown();
    }

    public function testUiFunctionsNeedAWebRequest(): void
    {
        // On the command line there is no page to draw on.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('browser');

        title('Hello');
    }

    public function testConfigureSetsTheDefaults(): void
    {
        self::assertNull(Runtime::config()->auth);
        self::assertSame('Tools', Runtime::config()->name);

        configure(auth: static fn (): string => 'ava', name: 'Back office');
        configure(debug: false);

        $config = Runtime::config();
        self::assertNotNull($config->auth);
        self::assertSame('ava', ($config->auth)());
        self::assertSame('Back office', $config->name);
        self::assertFalse($config->debug);
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
