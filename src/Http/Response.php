<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Http;

/**
 * What goes back to the browser.
 */
final class Response
{
    /**
     * @param int                  $status      the HTTP status
     * @param string               $body        the page, or the file of a download
     * @param array<string,string> $headers     the headers that belong to the answer, by name
     * @param bool                 $alreadySent the script answered on its own; there is nothing left to send
     */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        public readonly array $headers = [],
        public readonly bool $alreadySent = false,
    ) {
    }

    /** One header by its name, in whatever case it is written. null when the answer has none of that name. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** Sends the status, the headers and the body. It does nothing when the script answered on its own. */
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
