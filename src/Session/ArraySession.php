<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Session;

/**
 * A session that lives in memory. For tests.
 */
final class ArraySession implements Session
{
    /** @var array<string,mixed> */
    private array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }
}
