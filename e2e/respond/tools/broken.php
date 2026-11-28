<?php
// Break down
// A tool that runs into a fatal error: PHP ends, and nothing can catch it.

use function Runio\ScriptUi\{title, note};

title('Break down');
note('Drawn before the end.');
echo 'Printed before the end.';

eval('function declared_twice_by_a_test(): void {}');
eval('function declared_twice_by_a_test(): void {}');
