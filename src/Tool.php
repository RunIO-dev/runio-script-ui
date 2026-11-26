<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

/**
 * One tool: a PHP file in the tools folder.
 */
final class Tool
{
    public function __construct(
        public readonly string $id,
        public readonly string $file,
        public readonly string $title,
        public readonly string $description,
    ) {
    }
}
