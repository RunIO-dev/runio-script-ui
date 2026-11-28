<?php

declare(strict_types=1);

/**
 * A setup that the README warns about, for tests/access.spec.mjs: this script
 * lies in the web root, and so does its folder of tools. The web server runs
 * tools/hello.php by its name, without this script having a say.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

Runio\ScriptUi\serve(__DIR__ . '/tools', name: 'In the web root');
