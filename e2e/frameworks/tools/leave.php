<?php
// Leave early
// A tool that ends PHP with exit. Nothing of the framework runs after that.

use function Runio\ScriptUi\{title, text, note};

title('Leave early');
$word = text('Word');
note('The word is "' . $word . '".');

exit;
