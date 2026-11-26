<?php

declare(strict_types=1);

/**
 * Settings for "script-ui serve examples/tools": the named arguments of Runio\ScriptUi\serve().
 *
 * There is no login check here, so the demo only answers on this computer.
 * On a real server you would add one:
 *
 *     'auth' => fn () => $_SESSION['admin_email'] ?? null,
 */

return [
    'name' => 'Shop back office',

    // Called for every button a user presses. Here: one JSON line per action.
    'log' => static function (array $entry): void {
        $file = __DIR__ . '/../var/audit.log';
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    },
];
