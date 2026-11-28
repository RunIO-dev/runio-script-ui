<?php

declare(strict_types=1);

/**
 * A setup that the README warns about, for tests/access.spec.mjs: this script
 * lies in the web root, and so does its folder of tools. The web server runs
 * tools/hello.php by its name, without this script having a say.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// For tests/endings.spec.mjs: a step that an application added to the shutdown
// of PHP, and that raises a warning. It was added before serve(), so it runs
// before the step of the library. From then on the last error PHP knows of is
// this warning, and no longer the fatal error that ended a tool.
if (isset($_GET['warn-at-the-end'])) {
    register_shutdown_function(static function (): void {
        trigger_error('raised by the application while PHP shut down', E_USER_WARNING);
    });
}

Runio\ScriptUi\serve(__DIR__ . '/tools', name: 'In the web root');
