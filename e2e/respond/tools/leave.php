<?php
// Leave early
// A tool that ends PHP by itself.

use function Runio\ScriptUi\{title, text, note};

title('Leave early');
$word = text('Word');
note('The word is "' . $word . '".');

// Nothing after this line runs: not the rest of this file, and not the
// application that called respond().
exit;
