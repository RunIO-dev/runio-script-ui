<?php
// Answer alone and leave
// The way a file is often sent by hand: an answer of its own, then exit.

use function Runio\ScriptUi\title;

title('Answer alone and leave');

ob_end_clean();
header('Content-Type: text/plain; charset=utf-8');
echo 'An answer, and then the end of PHP.';
exit;
