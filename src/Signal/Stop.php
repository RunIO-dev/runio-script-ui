<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Signal;

/**
 * Thrown by stop() to end the run of a tool early. The page is still drawn.
 *
 * It extends Error, not Exception, so that a "catch (\Exception)" in a tool
 * does not swallow it by accident.
 *
 * @internal
 */
class Stop extends \Error
{
}
