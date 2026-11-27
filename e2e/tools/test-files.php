<?php
// Test: several files
// Only for the browser tests: a field that takes several files, next to a text field and a button.

use function Runio\ScriptUi\{title, text, note, file_input, table, button, success};

title('Test: several files');

$owner = text('Owner');
note('Owner: ' . $owner);

$files = file_input('Attachments', multiple: true, maxSize: 200_000);

table(
    array_map(static fn ($file): array => ['name' => $file->name, 'bytes' => $file->size, 'starts with' => substr($file->contents(), 0, 12)], $files),
    empty: 'No file yet.',
);

if (button('Count them')) {
    success('Counted ' . count($files) . ' for "' . $owner . '".');
}
