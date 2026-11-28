<?php
// Answer alone
// A tool that closes its page and prints an answer of its own.

use function Runio\ScriptUi\title;

title('Answer alone');

ob_end_clean();
http_response_code(203);
header('Content-Type: text/plain; charset=utf-8');
header('X-Tool: own');
// A header of its own in two lines, spelled in two ways.
header('X-Tool-List: a', false);
header('x-tool-list: b', false);
// One more line of a header that the application set before.
header('X-App-Early: three', false);
// And a header of the application that it puts its own value in the place of.
header('X-App-Mode: tool');
setcookie('first', '1');
setcookie('second', '2');
echo 'An answer of the tool itself.';
