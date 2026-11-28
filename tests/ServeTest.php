<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Config;
use Runio\ScriptUi\Http\Request;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Tests\Support\ToolTestCase;
use Runio\ScriptUi\ToolFinder;

/**
 * Serving a whole folder of tool files: the index page and the routing.
 */
final class ServeTest extends ToolTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/script-ui-serve-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);

        file_put_contents($this->dir . '/hello.php', <<<'PHP'
            <?php
            // Say hello
            // Greets whoever you name.

            use function Runio\ScriptUi\{title, text, note};

            title('Hello');
            $name = text('Name', default: 'world');
            note("Hello, $name!");
            PHP);
        file_put_contents($this->dir . '/boom.php', "<?php\n// Boom\nthrow new RuntimeException('kaboom');\n");
        file_put_contents($this->dir . '/_helper.php', "<?php // not a tool\n");
        file_put_contents($this->dir . '/notes.txt', 'not a tool either');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);

        parent::tearDown();
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     */
    private function serve(string $method, string $path, array $query = [], array $post = [], ?string $base = null): Response
    {
        if ($method === 'POST') {
            $post += ['_token' => $this->token()];
        }

        return $this->kernel()->serve(new Request($method, $path, $query, $post), new ToolFinder($this->dir), $base);
    }

    public function testTheIndexListsEveryTool(): void
    {
        $response = $this->serve('GET', '/tools.php');

        self::assertSame(200, $response->status);
        self::assertSame(['Boom', 'Say hello'], $this->texts($response, '//a[contains(@class,"sui-card")]//strong'));
        self::assertSame(
            ['/tools.php?_tool=boom', '/tools.php?_tool=hello'],
            $this->texts($response, '//a[contains(@class,"sui-card")]/@href'),
        );
        self::assertStringContainsString('Greets whoever you name.', $response->body);
        self::assertSame(['Tools'], $this->texts($response, '//h1'));
        self::assertNotNull($response->header('Content-Security-Policy'));
    }

    public function testAToolIsChosenWithAQueryParameter(): void
    {
        $response = $this->serve('GET', '/tools.php', ['_tool' => 'hello']);

        self::assertSame(200, $response->status);
        self::assertSame(['Hello, world!'], $this->texts($response, '//p[contains(@class,"sui-note")]'));
        self::assertSame('/tools.php?_tool=hello', $this->attr($response, '//form[@id="sui-form"]', 'action'));
        self::assertSame('/tools.php', $this->attr($response, '//a[contains(@class,"sui-home")]', 'href'));
        self::assertMatchesRegularExpression(
            '~^/tools\.php\?_tool=hello&_reset=[0-9a-f]{24}$~',
            (string) $this->attr($response, '//a[contains(@class,"sui-reset")]', 'href'),
        );
    }

    public function testTheRoutingParameterIsNotAFormField(): void
    {
        // A field that happens to be called "tool" must not pick up the routing value.
        file_put_contents($this->dir . '/pick.php', "<?php\nRunio\\ScriptUi\\note('[' . Runio\\ScriptUi\\text('_tool', key: 'tool') . ']');\n");

        $response = $this->serve('GET', '/tools.php', ['_tool' => 'pick']);

        self::assertSame(['[]'], $this->texts($response, '//p[contains(@class,"sui-note")]'));
    }

    public function testWithABasePathToolsHaveCleanAddresses(): void
    {
        $response = $this->serve('GET', '/', base: '');
        self::assertSame(['/boom', '/hello'], $this->texts($response, '//a[contains(@class,"sui-card")]/@href'));

        $response = $this->serve('GET', '/hello', base: '');
        self::assertSame(200, $response->status);
        self::assertSame('/hello', $this->attr($response, '//form[@id="sui-form"]', 'action'));
        self::assertSame('/', $this->attr($response, '//a[contains(@class,"sui-home")]', 'href'));

        $response = $this->serve('GET', '/admin/tools', base: '/admin/tools');
        self::assertSame(['/admin/tools/boom', '/admin/tools/hello'], $this->texts($response, '//a[contains(@class,"sui-card")]/@href'));

        $response = $this->serve('GET', '/admin/tools/hello', base: '/admin/tools/');
        self::assertSame(200, $response->status);
        self::assertSame('/admin/tools/hello', $this->attr($response, '//form[@id="sui-form"]', 'action'));
        self::assertSame('/admin/tools/', $this->attr($response, '//a[contains(@class,"sui-home")]', 'href'));
    }

    public function testTheListIsAtTheBaseAsItIsWritten(): void
    {
        $home = fn (?string $base, string $path): ?string => $this->attr($this->serve('GET', $path, base: $base), '//a[contains(@class,"sui-home")]', 'href');

        // Symfony and Laravel answer "/admin/tools/" with a redirect to the address
        // without the slash. A folder that a web server serves is the other way
        // round. So the link to the list ends as the base ends.
        self::assertSame('/admin/tools', $home('/admin/tools', '/admin/tools/hello'));
        self::assertSame('/admin/tools/', $home('/admin/tools/', '/admin/tools/hello'));
        self::assertSame('/admin/tools', $home('admin/tools', '/admin/tools/hello'));

        // At the top of a site the list is at "/", however that is written.
        self::assertSame('/', $home('', '/hello'));
        self::assertSame('/', $home('/', '/hello'));

        // Both spellings of the address are answered, whichever the base has.
        foreach (['/admin/tools', '/admin/tools/'] as $base) {
            foreach (['/admin/tools', '/admin/tools/'] as $path) {
                self::assertSame(['Boom', 'Say hello'], $this->texts($this->serve('GET', $path, base: $base), '//a[contains(@class,"sui-card")]//strong'), $base . ' ' . $path);
            }
        }

        // The way back from a page that turns somebody away follows the same rule.
        $missing = $this->serve('GET', '/admin/tools/missing', base: '/admin/tools');
        self::assertSame(404, $missing->status);
        self::assertSame('/admin/tools', $this->attr($missing, '//a[contains(@class,"sui-link")]', 'href'));
    }

    public function testSubmittingAServedToolWorks(): void
    {
        $response = $this->serve('POST', '/hello', post: ['name' => 'Ava'], base: '');

        self::assertSame(200, $response->status);
        self::assertSame(['Hello, Ava!'], $this->texts($response, '//p[contains(@class,"sui-note")]'));

        $response = $this->serve('GET', '/hello', base: '');
        self::assertSame(['Hello, Ava!'], $this->texts($response, '//p[contains(@class,"sui-note")]'));
    }

    public function testAServedToolRemembersItsInputsWithBothKindsOfAddress(): void
    {
        // Chosen with a query parameter ...
        $this->serve('POST', '/tools.php', ['_tool' => 'hello'], ['name' => 'Ava']);
        $response = $this->serve('GET', '/tools.php', ['_tool' => 'hello']);
        self::assertSame(['Hello, Ava!'], $this->texts($response, '//p[contains(@class,"sui-note")]'));

        // ... and a link can still fill the field in for one page view.
        $response = $this->serve('GET', '/tools.php', ['_tool' => 'hello', 'name' => 'Ben']);
        self::assertSame(['Hello, Ben!'], $this->texts($response, '//p[contains(@class,"sui-note")]'));
        self::assertSame('/tools.php?_tool=hello', $this->attr($response, '//form[@id="sui-form"]', 'action'));

        $response = $this->serve('GET', '/tools.php', ['_tool' => 'hello']);
        self::assertSame(['Hello, Ava!'], $this->texts($response, '//p[contains(@class,"sui-note")]'));

        // With clean addresses.
        $this->serve('POST', '/admin/tools/hello', [], ['name' => 'Cy'], '/admin/tools');
        $response = $this->serve('GET', '/admin/tools/hello', [], [], '/admin/tools');
        self::assertSame(['Hello, Cy!'], $this->texts($response, '//p[contains(@class,"sui-note")]'));
    }

    public function testUnknownToolsAreNotFound(): void
    {
        foreach (['missing', '_helper', '../hello', 'hello.php', 'notes.txt', 'HELLO', ''] as $id) {
            $response = $this->serve('GET', '/tools.php', ['_tool' => $id]);
            self::assertSame($id === '' ? 200 : 404, $response->status, var_export($id, true));
        }

        foreach (['/missing', '/hello/extra', '/_helper', '/hello.php', '/%2e%2e/hello'] as $path) {
            self::assertSame(404, $this->serve('GET', $path, base: '')->status, $path);
        }

        self::assertSame(404, $this->serve('GET', '/elsewhere/hello', base: '/admin/tools')->status);
        self::assertSame(404, $this->serve('GET', '/tools.php', ['_tool' => ['hello']])->status);
    }

    public function testAToolThatThrowsStillShowsAPage(): void
    {
        $response = $this->serve('GET', '/boom', base: '');

        self::assertSame(500, $response->status);
        self::assertStringContainsString('kaboom', $response->body);
        self::assertSame(1, $this->hits($response, '//form[@id="sui-form"]'));
    }

    public function testTheLoginCheckComesBeforeEverythingElse(): void
    {
        $this->config = new Config(debug: true);
        $finder = new ToolFinder($this->dir);
        $kernel = $this->kernel();

        foreach (['/', '/hello', '/missing'] as $path) {
            $request = new Request('GET', $path, [], [], ['host' => 'tools.example.com'], '203.0.113.9');
            $response = $kernel->serve($request, $finder, '');

            self::assertSame(403, $response->status, $path);
            self::assertStringNotContainsString('Say hello', $response->body);
        }
    }

    public function testAnEmptyFolderExplainsWhatToDo(): void
    {
        $empty = $this->dir . '-empty';
        mkdir($empty);

        try {
            $response = $this->kernel()->serve(new Request('GET', '/'), new ToolFinder($empty), '');
        } finally {
            rmdir($empty);
        }

        self::assertSame(200, $response->status);
        self::assertSame(0, $this->hits($response, '//a[contains(@class,"sui-card")]'));
        self::assertStringContainsString('No tools yet', $response->body);
    }
}
