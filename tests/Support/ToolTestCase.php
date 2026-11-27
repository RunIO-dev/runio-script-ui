<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests\Support;

use PHPUnit\Framework\TestCase;
use Runio\ScriptUi\Config;
use Runio\ScriptUi\Http\Request;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Kernel;
use Runio\ScriptUi\Session\ArraySession;

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

    private ?string $errorLogFile = null;

    private string|false $errorLogBefore = false;

    protected function setUp(): void
    {
        $this->session = new ArraySession();
        $this->config = null;
        $this->logged = [];
    }

    protected function tearDown(): void
    {
        if ($this->errorLogFile !== null) {
            ini_set('error_log', (string) $this->errorLogBefore);
            @unlink($this->errorLogFile);
            $this->errorLogFile = null;
        }
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

        return new Kernel($this->config, $this->session);
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
     */
    protected function post(callable $tool, array $fields = [], array $headers = []): Response
    {
        $fields += ['_token' => $this->token()];

        return $this->kernel()->handle(new Request('POST', '/tool', [], $fields, $headers), 'tool', $tool);
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
                $value = $field->textContent;
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
