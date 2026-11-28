<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Config;
use Runio\ScriptUi\Http\Request;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Tests\Support\ToolTestCase;
use Runio\ScriptUi\ToolFinder;

/**
 * A folder of tools that lies where the web server looks for files. The web
 * server runs such a file by its name, past serve() and the checks that were
 * given to it. The library cannot stop that, and it cannot see whether the web
 * server was told to keep the folder closed. So it says what it sees, to
 * whoever sets the tools up, and to nobody else.
 */
final class WebRootTest extends ToolTestCase
{
    private const TOOL = "<?php\n// Say hello\n\nuse function Runio\\ScriptUi\\title;\n\ntitle('Hello');\n";

    /** A project: public/ is the web root, with one folder of tools inside it and two outside. */
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/script-ui-webroot-' . bin2hex(random_bytes(6));
        foreach (['/public/tools', '/tools', '/public-tools'] as $folder) {
            mkdir($this->root . $folder, 0777, true);
            file_put_contents($this->root . $folder . '/hello.php', self::TOOL);
        }
    }

    protected function tearDown(): void
    {
        self::remove($this->root);

        parent::tearDown();
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::remove($path . '/' . $name);
            }
        }
        rmdir($path);
    }

    /**
     * The list of tools, asked for from this computer. Without a login check
     * that is where error details are shown.
     */
    private function index(string $tools, ?string $webRoot, ?Config $config = null, string $path = '/'): Response
    {
        $this->config = $config ?? new Config();

        return $this->kernel()->serve(
            new Request('GET', $path, [], [], ['Host' => 'localhost:8765']),
            new ToolFinder($tools),
            '',
            $webRoot,
        );
    }

    /** @return list<string> */
    private function warnings(Response $response): array
    {
        return $this->texts($response, '//div[contains(@class,"sui-warning")]');
    }

    public function testAFolderOfToolsInTheWebRootIsPointedOut(): void
    {
        $response = $this->index($this->root . '/public/tools', $this->root . '/public');

        self::assertSame(200, $response->status);
        self::assertSame([
            'The folder of tools, ' . $this->root . '/public/tools, lies in the web root. '
            . 'The web server can run a tool file by its name, and then the login check and the access check of serve() are not asked. '
            . 'Move the folder out of the web root, or close it in the web server. '
            . 'You see this note because error details are shown here.',
        ], $this->warnings($response));
        // The folder stands there as code, in the direction a path is read.
        self::assertSame([$this->root . '/public/tools'], $this->texts($response, '//div[contains(@class,"sui-warning")]/code[@dir="ltr"]'));
        // The list is there all the same.
        self::assertSame(['Say hello'], $this->texts($response, '//a[contains(@class,"sui-card")]//strong'));
    }

    public function testTheWebRootItselfIsInTheWebRoot(): void
    {
        file_put_contents($this->root . '/public/hello.php', self::TOOL);

        self::assertCount(1, $this->warnings($this->index($this->root . '/public', $this->root . '/public')));
        // However the two are written.
        self::assertCount(1, $this->warnings($this->index($this->root . '/public/', $this->root . '/public')));
        self::assertCount(1, $this->warnings($this->index($this->root . '/public/tools/../tools', $this->root . '/tools/../public/')));
    }

    public function testAFolderOutsideTheWebRootIsFine(): void
    {
        self::assertSame([], $this->warnings($this->index($this->root . '/tools', $this->root . '/public')));
        // A folder next to the web root whose name only starts like it.
        self::assertSame([], $this->warnings($this->index($this->root . '/public-tools', $this->root . '/public')));
        // The web root lies in the folder of tools, not the other way round.
        self::assertSame([], $this->warnings($this->index($this->root, $this->root . '/public')));
    }

    public function testNothingIsSaidWhereTheWebRootIsNotKnown(): void
    {
        $inside = $this->root . '/public/tools';

        self::assertSame([], $this->warnings($this->index($inside, null)));
        // An empty path is the folder PHP works in for realpath(), and not a web root.
        self::assertSame([], $this->warnings($this->index($inside, '')));
        self::assertSame([], $this->warnings($this->index($inside, $this->root . '/gone')));
    }

    public function testAFolderThatIsReachedThroughALinkCounts(): void
    {
        // The way many servers are set up: the web root is ".../current/public",
        // and "current" is a link to the release that is live.
        if (!@symlink($this->root, $this->root . '-current')) {
            self::markTestSkipped('This system makes no links.');
        }

        try {
            $response = $this->index($this->root . '/public/tools', $this->root . '-current/public');
            self::assertCount(1, $this->warnings($response));

            $response = $this->index($this->root . '-current/public/tools', $this->root . '/public');
            self::assertCount(1, $this->warnings($response));

            self::assertSame([], $this->warnings($this->index($this->root . '-current/tools', $this->root . '/public')));
        } finally {
            unlink($this->root . '-current');
        }
    }

    public function testOnlyWhoeverSeesErrorDetailsSeesTheNote(): void
    {
        $inside = $this->root . '/public/tools';
        $webRoot = $this->root . '/public';
        $login = static fn (): string => 'ava';

        // With a login check other people read the list. It tells them nothing about the server.
        $response = $this->index($inside, $webRoot, new Config(auth: $login));
        self::assertSame(200, $response->status);
        self::assertSame([], $this->warnings($response));
        self::assertStringNotContainsString($this->root, $response->body);

        self::assertSame([], $this->warnings($this->index($inside, $webRoot, new Config(debug: false))));

        // Whoever develops with a login check passes debug: true, and sees it.
        self::assertCount(1, $this->warnings($this->index($inside, $webRoot, new Config(auth: $login, debug: true))));
    }

    public function testTheNoteStandsOnTheListAndNotOnEveryTool(): void
    {
        $response = $this->index($this->root . '/public/tools', $this->root . '/public', path: '/hello');

        self::assertSame(200, $response->status);
        self::assertSame(['Hello'], $this->texts($response, '//h1'));
        self::assertSame([], $this->warnings($response));
    }

    public function testTheNoteStandsAboveAnEmptyListToo(): void
    {
        unlink($this->root . '/public/tools/hello.php');

        $response = $this->index($this->root . '/public/tools', $this->root . '/public');

        self::assertCount(1, $this->warnings($response));
        self::assertCount(1, $this->texts($response, '//div[contains(@class,"sui-info")]'));
    }

    public function testTheNoteComesInTheLanguageOfTheList(): void
    {
        $inside = $this->root . '/public/tools';
        $webRoot = $this->root . '/public';

        $german = $this->warnings($this->index($inside, $webRoot, new Config(language: 'de')));
        self::assertCount(1, $german);
        self::assertStringStartsWith('Der Ordner mit den Werkzeugen, ' . $inside . ', liegt im Web-Root.', $german[0]);

        $arabic = $this->warnings($this->index($inside, $webRoot, new Config(language: 'ar')));
        self::assertCount(1, $arabic);
        self::assertStringStartsWith('مجلد الأدوات ' . $inside . ' يقع داخل جذر الموقع', $arabic[0]);
    }

    public function testTheNameOfTheFolderIsTextAndNeverMarkup(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Windows takes no "<" in the name of a folder.');
        }

        $odd = $this->root . '/public/<i>tools';
        mkdir($odd);
        file_put_contents($odd . '/hello.php', self::TOOL);

        $response = $this->index($odd, $this->root . '/public');

        self::assertSame([$odd], $this->texts($response, '//div[contains(@class,"sui-warning")]/code'));
        self::assertSame(0, $this->hits($response, '//div[contains(@class,"sui-warning")]//i'));

        // Your own words for the note are text as well, and the folder still goes in.
        $own = $this->index($odd, $this->root . '/public', new Config(texts: ['en' => ['index.in_web_root' => 'Move <b>{folder}</b> & reload.']]));
        self::assertSame(['Move <b>' . $odd . '</b> & reload.'], $this->warnings($own));
        self::assertSame(0, $this->hits($own, '//div[contains(@class,"sui-warning")]//b'));
    }
}
