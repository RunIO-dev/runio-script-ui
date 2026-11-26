<?php

declare(strict_types=1);

/**
 * Entry point for PHP's built-in web server, started by "script-ui serve".
 *
 * Every address is answered here, so the server never hands out a file from
 * disk by its name.
 *
 * Settings can live next to the tools in "_config.php", a file that returns the
 * named arguments of Runio\ScriptUi\serve():
 *
 *     return ['name' => 'Back office', 'auth' => fn () => $_SESSION['admin'] ?? null];
 */

$directory = getenv('SCRIPT_UI_TOOLS');
if (!is_string($directory) || $directory === '' || !is_dir($directory)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Script UI: the tools folder is missing. Start the server with "script-ui serve <folder>".';

    return;
}

$autoload = getenv('SCRIPT_UI_AUTOLOAD');
require_once is_string($autoload) && $autoload !== '' ? $autoload : dirname(__DIR__) . '/vendor/autoload.php';

$options = [];
$configFile = rtrim($directory, '/\\') . '/_config.php';
if (is_file($configFile)) {
    $options = (static fn (string $file): mixed => require $file)($configFile);
    if (!is_array($options)) {
        throw new UnexpectedValueException('_config.php has to return an array of settings for Runio\ScriptUi\serve().');
    }
}

// The tools sit at the top of this server: "/refund" is the tool refund.php.
unset($options['directory'], $options['base']);
Runio\ScriptUi\serve($directory, ...$options, base: '');
