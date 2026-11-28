<?php

// A script on its own that ends PHP with exit. It has no login check: only this computer gets in.
require_once __DIR__ . '/../../vendor/autoload.php';

use function Runio\ScriptUi\{title, text, note};

title('Leave early');
$word = text('Word');
note('The word is "' . $word . '".');

// Nothing after this line runs.
exit;
