<?php
// Test: broken
// A tool that runs into a fatal error, for the browser tests. PHP ends, and nothing can catch it.

use function Runio\ScriptUi\{title, note};

title('Test: broken');
note('Drawn before the end.');

eval('function declared_twice_by_a_test(): void {}');
eval('function declared_twice_by_a_test(): void {}');
