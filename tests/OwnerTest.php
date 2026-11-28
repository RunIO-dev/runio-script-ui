<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Config;
use Runio\ScriptUi\Http\Request;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Tests\Support\ToolTestCase;
use Runio\ScriptUi\UploadFolder;
use Runio\ScriptUi\Uploads;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\file_input;
use function Runio\ScriptUi\rerun;
use function Runio\ScriptUi\success;
use function Runio\ScriptUi\text;

/**
 * What the library keeps in a session belongs to the user who was logged in
 * when it was kept. An application may put another user into the same
 * session: a login without a logout before it, which leaves the session as it
 * is in Laravel and in Symfony, or one person who acts as another. Nothing of
 * the one before is handed on then.
 */
final class OwnerTest extends ToolTestCase
{
    /** What the login check answers. */
    private mixed $login = 'ava';

    protected function setUp(): void
    {
        parent::setUp();

        $this->login = 'ava';
        $this->config = new Config(auth: fn (): mixed => $this->login, debug: true);
    }

    private static function note(): \Closure
    {
        return static function (): void {
            text('Note');
            button('Save');
        };
    }

    private function noteOf(Response $response): ?string
    {
        return $this->attr($response, '//input[@name="note"]', 'value');
    }

    public function testWhatAToolKeptIsGoneForAnotherUserInTheSameSession(): void
    {
        $this->post(self::note(), ['note' => 'the plan of ava', '_click' => 'save']);
        self::assertSame('the plan of ava', $this->noteOf($this->get(self::note())));

        $this->login = 'ben';
        $page = $this->get(self::note());

        self::assertSame(200, $page->status);
        self::assertSame('', $this->noteOf($page));
        self::assertStringNotContainsString('the plan of ava', $page->body);

        // It is gone, and not put aside: it does not come back with its owner.
        $this->login = 'ava';
        self::assertSame('', $this->noteOf($this->get(self::note())));
    }

    public function testTheSameUserKeepsEverything(): void
    {
        $this->post(self::note(), ['note' => 'the plan of ava', '_click' => 'save']);
        $token = $this->token();

        // Whatever the login check hands out for one person: the name is what counts.
        foreach (['ava', new class () {
            public function getUserIdentifier(): string
            {
                return 'ava';
            }
        }] as $login) {
            $this->login = $login;
            self::assertSame('the plan of ava', $this->noteOf($this->get(self::note())));
        }
        self::assertSame($token, $this->token());
    }

    public function testAFormOfTheUserBeforeIsNotTakenFromTheNextOne(): void
    {
        $ran = false;
        $tool = function () use (&$ran): void {
            if (button('Delete')) {
                $ran = true;
            }
        };
        $this->get($tool);
        $token = $this->token();

        // A page that the first user left open, in a browser that the second one uses now.
        $this->login = 'ben';
        $response = $this->kernel()->handle(new Request('POST', '/tool', [], ['_token' => $token, '_click' => 'delete']), 'tool', $tool);

        self::assertSame(419, $response->status);
        self::assertFalse($ran);
        self::assertNotSame($token, $this->token());
    }

    public function testTheFilesOfTheUserBeforeAreDeleted(): void
    {
        $tool = static function (): void {
            file_input('Report');
        };
        $uploaded = $this->post($tool, files: ['report' => $this->arrived('salaries.csv', "name,pay\nAva,1\n")]);
        self::assertSame(['salaries.csv'], $this->texts($uploaded, '//*[contains(@class,"sui-file-name")]'));
        self::assertCount(1, $this->stored());
        $handle = $this->attr($uploaded, '//input[@name="_file[report][]"]', 'value');
        self::assertIsString($handle);

        $this->login = 'ben';
        $page = $this->get($tool);

        self::assertSame([], $this->texts($page, '//*[contains(@class,"sui-file-name")]'));
        self::assertSame([], $this->stored());

        // Nor does the name the page gave the file open anything any more.
        $named = $this->post($tool, ['_file' => ['report' => [$handle]]]);
        self::assertSame([], $this->texts($named, '//*[contains(@class,"sui-file-name")]'));
    }

    public function testForgettingEveryFileOfASessionLeavesNoListOfThemBehind(): void
    {
        $tool = static function (): void {
            file_input('Report');
        };
        $this->post($tool, files: ['report' => $this->arrived('salaries.csv', "name,pay\nAva,1\n")]);
        self::assertCount(1, $this->stored());
        self::assertNotNull($this->session->get('uploads'));

        // Asked by any tool: the files of every tool of the session go.
        (new Uploads(new UploadFolder($this->uploadFolder), $this->session, 'another-tool'))->forgetEverything();

        self::assertSame([], $this->stored());
        self::assertNull($this->session->get('uploads'));
    }

    public function testAMessageThatWaitedForTheUserBeforeIsNotShown(): void
    {
        $tool = static function (): void {
            if (button('Go')) {
                success('Paid 500 to ava.');
                rerun();
            }
        };
        // The message waits in the session for the page that is loaded next.
        self::assertSame(303, $this->post($tool, ['_click' => 'go'])->status);

        $this->login = 'ben';

        self::assertStringNotContainsString('Paid 500', $this->get($tool)->body);
    }

    public function testALoginCheckThatNamesNobodyCannotTellUsersApart(): void
    {
        // "true" says that somebody is logged in, and not who. Then the session
        // is all there is to go by, as it was before.
        $this->login = true;
        $this->post(self::note(), ['note' => 'kept', '_click' => 'save']);

        self::assertSame('kept', $this->noteOf($this->get(self::note())));

        // Once it does name somebody, what nobody owned is not handed to them.
        $this->login = 'ben';
        self::assertSame('', $this->noteOf($this->get(self::note())));
    }

    public function testASessionFromBeforeThisRuleIsNotEmptied(): void
    {
        // A session that was begun by an older version has no owner yet. Its
        // user finds everything as it was.
        $this->post(self::note(), ['note' => 'from before', '_click' => 'save']);
        $this->session->remove('owner');

        self::assertSame('from before', $this->noteOf($this->get(self::note())));

        // From then on it has one.
        $this->login = 'ben';
        self::assertSame('', $this->noteOf($this->get(self::note())));
    }

    public function testWithoutALoginCheckNothingIsEverEmptied(): void
    {
        $this->config = new Config(debug: true);
        $local = ['Host' => 'localhost:8765'];
        $kernel = fn (): \Runio\ScriptUi\Kernel => $this->kernel();

        $token = (function () use ($kernel, $local): string {
            $kernel()->handle(new Request('GET', '/tool', [], [], $local), 'tool', self::note());

            return $this->token();
        })();
        $kernel()->handle(new Request('POST', '/tool', [], ['_token' => $token, 'note' => 'mine', '_click' => 'save'], $local), 'tool', self::note());

        self::assertSame('mine', $this->noteOf($kernel()->handle(new Request('GET', '/tool', [], [], $local), 'tool', self::note())));
        self::assertSame($token, $this->token());
    }
}
