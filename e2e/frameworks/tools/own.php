<?php
// Answer alone
// A tool that closes its page and prints an answer of its own.

use function Runio\ScriptUi\title;

title('Answer alone');

ob_end_clean();
header('Content-Type: text/plain; charset=utf-8');
echo 'An answer of the tool itself: <b>not HTML</b>.';
