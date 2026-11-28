<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

/**
 * Calls back when the function that holds it in a variable is left, in
 * whatever way: by its end, by an error, and by exit.
 *
 * exit is the reason this exists. It runs no "finally" block and cannot be
 * caught. It does take away the variables of every function it leaves, right
 * then, before PHP starts to shut down, and an object in such a variable is
 * told that it goes.
 *
 * @internal
 */
final class OnLeave
{
    public function __construct(private readonly \Closure $then)
    {
    }

    public function __destruct()
    {
        ($this->then)();
    }
}
