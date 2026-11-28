<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Session;

/**
 * The session of a framework. Symfony's session has get() and set(), Laravel's
 * has get() and put(). It is taken by its methods and not by its class, so the
 * library needs neither framework to be installed.
 *
 * Everything the library keeps sits in one array under one key, as it does in
 * PHP's own session. That key is all the application sees of it. When the
 * application ends the session, at a logout, the state of every tool goes with it.
 */
final class FrameworkSession implements Session
{
    private const ROOT = '_script_ui';

    private readonly object $session;

    /** The method that writes a value: "set" in Symfony, "put" in Laravel. */
    private readonly string $write;

    public function __construct(object $session)
    {
        // session() in Laravel hands out a manager of sessions. It has none of the
        // methods itself and passes calls on. The session is what driver() returns.
        if (!method_exists($session, 'get') && method_exists($session, 'driver')) {
            $driver = $session->driver();
            $session = is_object($driver) ? $driver : $session;
        }

        $write = match (true) {
            !method_exists($session, 'get') => null,
            method_exists($session, 'set') => 'set',
            method_exists($session, 'put') => 'put',
            default => null,
        };
        if ($write === null) {
            throw new \InvalidArgumentException(sprintf(
                'session: has to be the session of your application: an object with get() and set(), as in Symfony, or with get() and put(), as in Laravel. %s is neither.',
                get_debug_type($session),
            ));
        }

        $this->session = $session;
        $this->write = $write;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $all = $this->all();
        $all[$key] = self::storable($value);
        $this->session->{$this->write}(self::ROOT, $all);
    }

    public function remove(string $key): void
    {
        $all = $this->all();
        unset($all[$key]);
        $this->session->{$this->write}(self::ROOT, $all);
    }

    /**
     * Writes the session now, where it can be told to. For a tool that ends PHP
     * by itself: the framework is not there any more to do it.
     */
    public function save(): void
    {
        if (method_exists($this->session, 'save')) {
            $this->session->save();
        }
    }

    /** @return array<array-key,mixed> */
    private function all(): array
    {
        $all = $this->session->get(self::ROOT);

        return is_array($all) ? $all : [];
    }

    /**
     * A framework may keep its session as JSON, and Laravel does unless it is
     * told otherwise. JSON takes no text that is not UTF-8 and no number that
     * is not finite. One such byte in a field somebody posted, and the whole
     * session of that user could not be saved any more.
     */
    private static function storable(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_scrub($value, 'UTF-8');
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }

        if (!is_array($value)) {
            return $value;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            $clean[is_string($key) ? mb_scrub($key, 'UTF-8') : $key] = self::storable($item);
        }

        return $clean;
    }
}
