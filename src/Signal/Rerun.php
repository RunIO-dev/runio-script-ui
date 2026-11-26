<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Signal;

/**
 * Thrown by rerun(): end this run, then load the page again from the top.
 *
 * @internal
 */
final class Rerun extends Stop
{
}
