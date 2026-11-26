<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Http\Request;
use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\note;
use function Runio\ScriptUi\rerun;
use function Runio\ScriptUi\success;
use function Runio\ScriptUi\text;
use function Runio\ScriptUi\title;

/**
 * The script of an open page reruns the tool in the background. It asks for the
 * content of the page only and swaps it in.
 */
final class FragmentTest extends ToolTestCase
{
    private const ASK = ['x-script-ui' => 'fragment'];

    public function testThePageScriptGetsTheContentOnly(): void
    {
        $response = $this->post(function (): void {
            title('Find orders');
            text('Name');
        }, ['name' => 'Ava'], self::ASK);

        self::assertSame(200, $response->status);
        self::assertSame('1', $response->header('X-Script-Ui-Fragment'));
        self::assertSame('Find orders · Tools', rawurldecode((string) $response->header('X-Script-Ui-Title')));
        self::assertStringStartsWith('<h1', $response->body);
        self::assertStringContainsString('value="Ava"', $response->body);

        foreach (['<html', '<head', '<style', '<script', '<form', '_token'] as $part) {
            self::assertStringNotContainsString($part, $response->body);
        }

        // Nothing in the content may run or style itself, so the policy allows no nonce.
        self::assertStringNotContainsString('nonce', (string) $response->header('Content-Security-Policy'));
    }

    public function testWithoutAskingTheAnswerIsAWholePage(): void
    {
        $response = $this->post(function (): void {
            title('Find orders');
        });

        self::assertNull($response->header('X-Script-Ui-Fragment'));
        self::assertStringContainsString('<html', $response->body);
    }

    public function testATitleInAnotherScriptSurvivesTheHeader(): void
    {
        $response = $this->get(function (): void {
            title('استرجاع طلب');
        }, self::ASK);

        $header = (string) $response->header('X-Script-Ui-Title');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9%._~-]+$/', $header, 'only characters that are safe in a header');
        self::assertSame('استرجاع طلب · Tools', rawurldecode($header));
    }

    public function testAnErrorIsSentAsContentToo(): void
    {
        $response = $this->post(function (): void {
            note('before');
            throw new \RuntimeException('it broke');
        }, [], self::ASK);

        self::assertSame(500, $response->status);
        self::assertSame('1', $response->header('X-Script-Ui-Fragment'));
        self::assertStringContainsString('before', $response->body);
        self::assertStringContainsString('it broke', $response->body);
        self::assertStringNotContainsString('<html', $response->body);
    }

    public function testARefusedRequestGetsAWholePage(): void
    {
        // The page script sees that this is not content and loads the page properly,
        // so the user reads why they were refused.
        $response = $this->kernel()->handle(
            new Request('POST', '/tool', [], ['_token' => 'wrong'], self::ASK),
            'tool',
            static function (): void {
            },
        );

        self::assertSame(419, $response->status);
        self::assertNull($response->header('X-Script-Ui-Fragment'));
        self::assertStringContainsString('<html', $response->body);
    }

    public function testAfterRerunTheReloadedPageIsContentAgain(): void
    {
        $tool = function (): void {
            if (button('Save')) {
                success('Saved.');
                rerun();
            }
            note('Body');
        };

        // The browser follows the redirect and repeats the header.
        $response = $this->post($tool, ['_click' => 'save'], self::ASK);
        self::assertSame(303, $response->status);

        $response = $this->get($tool, self::ASK);
        self::assertSame('1', $response->header('X-Script-Ui-Fragment'));
        self::assertStringContainsString('Saved.', $response->body);
        self::assertStringContainsString('Body', $response->body);
        self::assertStringNotContainsString('<html', $response->body);
    }
}
