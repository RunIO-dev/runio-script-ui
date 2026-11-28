<?php

declare(strict_types=1);

/**
 * Settings for the scripts of this folder, which the web server runs by
 * themselves: tests/access.spec.mjs. Every script includes this file first.
 *
 * The login check reads a cookie, so that a test can be anybody. The report
 * is for Ava only, and every other script is open to whoever is logged in.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

Runio\ScriptUi\configure(
    auth: static function (): ?string {
        $name = $_COOKIE['as'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    },
    allow: static fn (?string $user, string $tool): bool => $tool !== 'my_report' || $user === 'ava',
);
