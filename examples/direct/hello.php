<?php
// One script, opened directly. No serve(), no folder of tools:
//   php -S localhost:8766 -t examples/direct
// then open http://localhost:8766/hello.php

require __DIR__ . '/../../vendor/autoload.php';

use function Runio\ScriptUi\{title, text, number, button, success, note};

title('Hello');

$name = text('Your name', default: 'world');
$times = number('How many times', default: 1, min: 1, max: 5, step: 1);

if (button('Say hello', style: 'primary')) {
    success(implode("\n", array_fill(0, (int) $times, "Hello, $name!")));
}

note('This page is the script examples/direct/hello.php. Every change runs it again from the top.');
