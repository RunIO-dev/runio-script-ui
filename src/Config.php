<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

/**
 * How the tools are protected and presented.
 */
final class Config
{
    /**
     * The login check. It receives the request and returns who is asking:
     * a name or id to let them in, null or false to keep them out.
     * Without a login check the tools only answer on the machine they run on.
     */
    public readonly ?\Closure $auth;

    /** Called once for every button a user presses, with an array describing the action. */
    public readonly ?\Closure $log;

    /**
     * @param bool|null $debug show error details in the page. null means: only without a login check,
     *                         where nobody but this computer gets in
     * @param string    $name  the name of the tool collection, shown in the header and on the index page
     */
    public function __construct(
        ?callable $auth = null,
        ?callable $log = null,
        public readonly ?bool $debug = null,
        public readonly string $name = 'Tools',
    ) {
        $this->auth = $auth === null ? null : $auth(...);
        $this->log = $log === null ? null : $log(...);
    }

    /** A copy with the given settings replaced. Settings left out stay as they are. */
    public function with(?callable $auth = null, ?callable $log = null, ?bool $debug = null, ?string $name = null): self
    {
        return new self(
            $auth ?? $this->auth,
            $log ?? $this->log,
            $debug ?? $this->debug,
            $name ?? $this->name,
        );
    }
}
