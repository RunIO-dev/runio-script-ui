<?php
// Import a file

use function Runio\ScriptUi\{title, file_input, button, success};

title('Import a file');
$file = file_input('File', types: ['csv']);

if ($file && button('Read')) {
    success('Read ' . $file->name . ': ' . count($file->rows()) . ' rows.');
}
