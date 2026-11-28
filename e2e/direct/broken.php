<?php

// A script on its own that runs into a fatal error. PHP ends, and nothing can catch it.
require_once __DIR__ . '/../../vendor/autoload.php';

use function Runio\ScriptUi\{title, note};

title('Break down');
note('Drawn before the end.');

eval('function declared_twice_by_a_test(): void {}');
eval('function declared_twice_by_a_test(): void {}');
