<?php
// Answer alone
// A tool that closes its page and prints an answer of its own.

use function Runio\ScriptUi\title;

title('Answer alone');

ob_end_clean();
http_response_code(203);
header('Content-Type: text/plain; charset=utf-8');
header('X-Tool: own');
setcookie('first', '1');
setcookie('second', '2');
echo 'An answer of the tool itself.';
