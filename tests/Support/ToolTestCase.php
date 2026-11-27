<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests\Support;

use PHPUnit\Framework\TestCase;
use Runio\ScriptUi\Config;
use Runio\ScriptUi\Http\Request;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Kernel;
use Runio\ScriptUi\Session\ArraySession;
use Runio\ScriptUi\UploadFolder;

/**
 * Runs a tool (a closure) through the kernel the way a browser session would.
 * The session is shared by every request made in one test.
 */
abstract class ToolTestCase extends TestCase
{
    protected ArraySession $session;

    /** Set this before the first request to run with another configuration. */
    protected ?Config $config = null;

    /** @var list<array<string,mixed>> */
    protected array $logged = [];

    /** Where uploaded files are kept in this test. Made on first use and removed after the test. */
    protected ?string $uploadFolder = null;

    /** The largest file PHP takes in this test, in bytes. 0 means no limit. */
    protected int $fileLimit = 1048576;

    /** The largest request PHP takes in this test, in bytes. 0 means no limit. */
    protected int $bodyLimit = 4194304;

    /** Whether PHP takes uploads at all in this test. */
    protected bool $uploadsOn = true;

    /** How many files PHP takes in one request in this test. */
    protected int $countLimit = 20;

    /** The time in this test. null is the real time. */
    protected ?int $now = null;

    /** @var list<string> files that wait to be picked up, as PHP leaves them after an upload */
    private array $arrived = [];

    private ?string $errorLogFile = null;

    private string|false $errorLogBefore = false;

    protected function setUp(): void
    {
        $this->session = new ArraySession();
        $this->config = null;
        $this->logged = [];
        $this->uploadFolder = null;
        $this->fileLimit = 1048576;
        $this->bodyLimit = 4194304;
        $this->uploadsOn = true;
        $this->countLimit = 20;
        $this->now = null;
    }

    protected function tearDown(): void
    {
        if ($this->errorLogFile !== null) {
            ini_set('error_log', (string) $this->errorLogBefore);
            @unlink($this->errorLogFile);
            $this->errorLogFile = null;
        }

        foreach ($this->arrived as $file) {
            @unlink($file);
        }
        $this->arrived = [];

        if ($this->uploadFolder !== null && is_dir($this->uploadFolder)) {
            foreach (scandir($this->uploadFolder) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($this->uploadFolder . '/' . $entry);
                }
            }
            @rmdir($this->uploadFolder);
        }
    }

    /**
     * A file the way PHP hands it to a script after an upload: it lies in a temp
     * folder under a name of PHP's choosing. Pass it to post() under the key of its field.
     *
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    protected function arrived(string $name, string $content, int $error = UPLOAD_ERR_OK): array
    {
        $file = '';
        if ($error === UPLOAD_ERR_OK) {
            $file = (string) tempnam(sys_get_temp_dir(), 'script-ui-arrived-');
            file_put_contents($file, $content);
            $this->arrived[] = $file;
        }

        return ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $file, 'error' => $error, 'size' => $error === UPLOAD_ERR_OK ? strlen($content) : 0];
    }

    /**
     * Several files for one field, in the shape PHP gives them.
     *
     * @param array<string,string> $files name => content
     *
     * @return array<string,list<mixed>>
     */
    protected function arrivedTogether(array $files): array
    {
        $together = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
        foreach ($files as $name => $content) {
            foreach ($this->arrived((string) $name, $content) as $part => $value) {
                $together[$part][] = $value;
            }
        }

        return $together;
    }

    /** The files that lie in the upload folder now. */
    protected function stored(): array
    {
        if ($this->uploadFolder === null || !is_dir($this->uploadFolder)) {
            return [];
        }

        return array_values(array_filter(
            scandir($this->uploadFolder) ?: [],
            static fn (string $entry): bool => preg_match('/^[0-9a-f]{32}\.upload$/', $entry) === 1,
        ));
    }

    /**
     * Sends PHP's error log to a temporary file for the rest of the test.
     *
     * @return string the path of that file
     */
    protected function captureErrorLog(): string
    {
        if ($this->errorLogFile === null) {
            $file = tempnam(sys_get_temp_dir(), 'script-ui-log-');
            self::assertIsString($file);
            $this->errorLogFile = $file;
            $this->errorLogBefore = ini_get('error_log');
            ini_set('error_log', $file);
        }

        return $this->errorLogFile;
    }

    protected function kernel(): Kernel
    {
        $this->config ??= new Config(
            auth: static fn (): string => 'tester',
            log: function (array $entry): void {
                $this->logged[] = $entry;
            },
            debug: true,
        );

        $this->uploadFolder ??= sys_get_temp_dir() . '/script-ui-test-' . bin2hex(random_bytes(6));

        return new Kernel($this->config, $this->session, new UploadFolder(
            $this->uploadFolder,
            // A test file never went through PHP's upload handling, so it is moved the plain way.
            rename(...),
            $this->fileLimit,
            $this->bodyLimit,
            $this->uploadsOn,
            fn (): int => $this->now ?? time(),
            countLimit: $this->countLimit,
        ));
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>  $query
     */
    protected function get(callable $tool, array $headers = [], array $query = []): Response
    {
        return $this->kernel()->handle(new Request('GET', '/tool', $query, [], $headers), 'tool', $tool);
    }

    /**
     * Submits the form with the current CSRF token, as the page itself would.
     *
     * @param array<string,mixed>  $fields
     * @param array<string,string> $headers
     * @param array<string,mixed>  $files   uploaded files, as PHP hands them over: see arrived()
     */
    protected function post(callable $tool, array $fields = [], array $headers = [], array $files = []): Response
    {
        $fields += ['_token' => $this->token()];

        return $this->kernel()->handle(new Request('POST', '/tool', [], $fields, $headers, files: $files), 'tool', $tool);
    }

    protected function token(): string
    {
        if (!is_string($this->session->get('csrf'))) {
            // The first page view creates the token.
            $this->get(static function (): void {
            });
        }

        $token = $this->session->get('csrf');
        self::assertIsString($token);

        return $token;
    }

    /**
     * The query string of the "Reset" link in the header of a page.
     *
     * @return array<array-key,mixed>
     */
    protected function resetQuery(Response $response): array
    {
        $href = (string) $this->attr($response, '//a[contains(@class,"sui-reset")]', 'href');
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * What a browser would send if the form of this page were submitted as it
     * stands: every hidden field, every text, the ticked boxes and the chosen options.
     * Add "_click" to press a button.
     *
     * @return array<array-key,mixed>
     */
    protected function form(Response $response): array
    {
        $pairs = [];
        foreach ($this->xpath($response)->query('//input[@name] | //select[@name] | //textarea[@name]') ?: [] as $field) {
            if (!$field instanceof \DOMElement || $field->hasAttribute('disabled')) {
                continue;
            }

            $type = strtolower($field->getAttribute('type'));
            if ($field->tagName === 'select') {
                $options = iterator_to_array($field->getElementsByTagName('option'));
                $chosen = array_values(array_filter($options, static fn (\DOMElement $option): bool => $option->hasAttribute('selected')))[0] ?? $options[0] ?? null;
                if ($chosen === null) {
                    continue;
                }
                $value = $chosen->getAttribute('value');
            } elseif ($field->tagName === 'textarea') {
                // A browser drops a line break right after the opening tag, and it
                // sends every other line break as "\r\n".
                $value = (string) preg_replace('/\r\n|\r|\n/', "\r\n", (string) preg_replace('/^\r?\n/', '', $field->textContent));
            } elseif (in_array($type, ['checkbox', 'radio'], true)) {
                if (!$field->hasAttribute('checked')) {
                    continue;
                }
                $value = $field->getAttribute('value');
            } elseif (in_array($type, ['submit', 'button', 'reset', 'file', 'image'], true)) {
                continue;
            } else {
                $value = $field->getAttribute('value');
            }

            $pairs[] = rawurlencode($field->getAttribute('name')) . '=' . rawurlencode($value);
        }

        parse_str(implode('&', $pairs), $fields);

        return $fields;
    }

    /**
     * What the tick box of one row sends. The row is the one in which a cell holds
     * exactly this text, and it has to be on the page that $response shows.
     */
    protected function tickBox(Response $response, int|string $cell, string $table = 'table'): string
    {
        foreach ($this->xpath($response)->query('//input[@name="_sel[' . $table . '][]"]') ?: [] as $box) {
            foreach ($box->parentNode?->parentNode?->childNodes ?? [] as $shown) {
                if ($box instanceof \DOMElement && trim((string) $shown->textContent) === (string) $cell) {
                    return $box->getAttribute('value');
                }
            }
        }

        self::fail('no tick box in a row that shows "' . $cell . '"');
    }

    /**
     * What the tick box of every row sends, on all pages of a table: the text of
     * the first cell of the row => what its box sends. The pages are turned the
     * way a user turns them. What the session kept before is put back afterwards.
     *
     * @return array<array-key,string>
     */
    protected function tickBoxes(callable $tool, string $table = 'table'): array
    {
        $kept = $this->session->get('state');
        $boxes = [];
        $response = $this->get($tool);

        while (true) {
            foreach ($this->xpath($response)->query('//input[@name="_sel[' . $table . '][]"]') ?: [] as $box) {
                $first = $box->parentNode?->nextSibling;
                if ($box instanceof \DOMElement && $first !== null) {
                    $shown = trim((string) $first->textContent);
                    self::assertArrayNotHasKey($shown, $boxes, 'two rows show "' . $shown . '" in their first cell, so it cannot tell them apart');
                    $boxes[$shown] = $box->getAttribute('value');
                }
            }

            $next = $this->attr($response, '//button[@id="p-' . $table . ':next"]', 'value');
            if ($next === null) {
                break;
            }
            $response = $this->post($tool, ['_click' => $next] + $this->form($response));
        }

        $this->session->set('state', $kept);

        return $boxes;
    }

    protected function xpath(Response $response): \DOMXPath
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $response->body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($dom);
    }

    /** @return list<string> the text of every node the query finds */
    protected function texts(Response $response, string $query): array
    {
        $texts = [];
        foreach ($this->xpath($response)->query($query) ?: [] as $node) {
            $texts[] = trim((string) $node->textContent);
        }

        return $texts;
    }

    /** How many nodes the query finds. */
    protected function hits(Response $response, string $query): int
    {
        $nodes = $this->xpath($response)->query($query);

        return $nodes === false ? 0 : $nodes->length;
    }

    /** The attribute of the first element the query finds, or null. */
    protected function attr(Response $response, string $query, string $attribute): ?string
    {
        $nodes = $this->xpath($response)->query($query);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof \DOMElement && $node->hasAttribute($attribute)
            ? $node->getAttribute($attribute)
            : null;
    }
}
