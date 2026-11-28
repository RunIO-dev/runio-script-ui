<?php
// Answer past everything
// A tool that closes every output buffer there is and prints an answer of its own.

use function Runio\ScriptUi\title;

title('Answer past everything');

// What many a script does before it sends a file by hand.
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/plain; charset=utf-8');
echo 'An answer that left at once.';
