<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Session;

/**
 * PHP's own session. If the application already started one, that one is used,
 * so a login check can read the application's session data. Everything the
 * library stores sits under one key and cannot collide with the application.
 */
final class NativeSession implements Session
{
    private const ROOT = '_script_ui';

    public function __construct(private readonly bool $secure = false)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->open();

        return $_SESSION[self::ROOT][$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->open();
        $_SESSION[self::ROOT][$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->open();
        unset($_SESSION[self::ROOT][$key]);
    }

    public function clear(): void
    {
        $this->open();
        $_SESSION[self::ROOT] = [];
    }

    private function open(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (session_status() === PHP_SESSION_DISABLED) {
                throw new \RuntimeException('Script UI needs PHP sessions, and they are disabled on this server.');
            }
            if (headers_sent($file, $line)) {
                throw new \RuntimeException(sprintf(
                    'The session could not start because output already began at %s:%d.',
                    $file,
                    $line,
                ));
            }

            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                // Never weaker than what php.ini asks for.
                'cookie_secure' => $this->secure || filter_var(ini_get('session.cookie_secure'), FILTER_VALIDATE_BOOL),
                'use_strict_mode' => true,
                'use_only_cookies' => true,
            ]);
        }

        if (!isset($_SESSION[self::ROOT]) || !is_array($_SESSION[self::ROOT])) {
            $_SESSION[self::ROOT] = [];
        }
    }
}
