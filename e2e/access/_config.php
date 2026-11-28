<?php

declare(strict_types=1);

/**
 * Settings for the browser tests of "who may open which tool": tests/access.spec.mjs.
 *
 * The login check reads a cookie, so that a test can be anybody. A real one
 * reads the session of the application. A second cookie closes one tool for
 * everybody, which is how a test takes access away while a page is open.
 */

$may = [
    'ava' => ['refund', 'report'],
    'ben' => ['report'],
];

return [
    'name' => 'Access test',
    'auth' => static function (): ?string {
        $name = $_COOKIE['as'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    },
    'allow' => static function (?string $user, string $tool) use ($may): bool {
        if (($_COOKIE['closed'] ?? null) === $tool) {
            return false;
        }

        return in_array($tool, $may[$user] ?? [], true);
    },
];
