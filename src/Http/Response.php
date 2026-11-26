<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Http;

/**
 * What goes back to the browser.
 */
final class Response
{
    /**
     * @param array<string,string> $headers
     * @param bool                 $alreadySent the script answered on its own; there is nothing left to send
     */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        public readonly array $headers = [],
        public readonly bool $alreadySent = false,
    ) {
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function send(): void
    {
        if ($this->alreadySent) {
            return;
        }

        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo $this->body;
    }
}
