<?php
// Test: leave
// A tool that ends PHP with exit, for the browser tests.

use function Runio\ScriptUi\{title, text, note};

title('Test: leave');
$word = text('Word');
note('The word is "' . $word . '".');

// Nothing after this line runs.
exit;
