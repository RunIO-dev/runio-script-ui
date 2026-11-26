<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Session;

/**
 * Where the library keeps what must survive between two requests of the same
 * visitor: the form token, the saved inputs of each tool and one-time messages.
 */
interface Session
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;
}
