<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Http;

/**
 * The parts of an HTTP request that the kernel needs. Built from PHP's globals
 * on a real request and by hand in tests.
 */
final class Request
{
    public readonly string $method;

    /** The path of the address, without the query string. It always starts with exactly one slash. */
    public readonly string $path;

    /** @var array<string,string> lower-case header name => value */
    private readonly array $headers;

    /**
     * @param array<array-key,mixed> $query   the parsed query string
     * @param array<array-key,mixed> $post    the parsed form body
     * @param array<string,string>   $headers header name => value, in any case
     * @param bool                   $secure   the browser reached the site over HTTPS
     * @param string                 $protocol the version of HTTP the request arrived with, such as "HTTP/1.1"
     * @param array<array-key,mixed> $files    the uploaded files, the way PHP hands them over in \$_FILES
     * @param int|null               $contentLength how many bytes the browser said it sends, when it said so
     */
    public function __construct(
        string $method,
        string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        array $headers = [],
        public readonly string $remoteAddr = '127.0.0.1',
        public readonly bool $secure = false,
        public readonly string $protocol = 'HTTP/1.1',
        public readonly array $files = [],
        public readonly ?int $contentLength = null,
    ) {
        $this->method = strtoupper($method);

        // A browser reads "//host/x" as an address on another site. The path ends up
        // in links and in the form's action, so it must stay on this site.
        $this->path = '/' . ltrim($path, '/\\');

        $normalised = [];
        foreach ($headers as $name => $value) {
            $normalised[strtolower((string) $name)] = (string) $value;
        }
        $this->headers = $normalised;
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (is_string($name) && is_string($value) && str_starts_with($name, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($name, 5))] = $value;
            }
        }

        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $https = is_string($_SERVER['HTTPS'] ?? null) && $_SERVER['HTTPS'] !== '' && strtolower($_SERVER['HTTPS']) !== 'off';

        // Behind a proxy that ends HTTPS, PHP itself sees plain HTTP. The proxy says
        // what the browser used. This only decides whether the session cookie is
        // marked "Secure", so a false claim can lock out nobody but its sender.
        $forwarded = strtolower(trim(explode(',', $headers['X-FORWARDED-PROTO'] ?? '')[0]));
        $length = $_SERVER['CONTENT_LENGTH'] ?? null;

        return new self(
            is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET',
            explode('?', $uri, 2)[0],
            $_GET,
            $_POST,
            $headers,
            is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '',
            $https || $forwarded === 'https',
            is_string($_SERVER['SERVER_PROTOCOL'] ?? null) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1',
            $_FILES,
            is_string($length) && preg_match('/^\d{1,18}$/D', $length) === 1 ? (int) $length : null,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
