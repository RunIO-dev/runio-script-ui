<?php
// Test: layout
// Parts that open and close, for the browser tests.

use function Runio\ScriptUi\{title, text, number, note, expander, button, success};

title('Test: layout');

$owner = text('Owner');

$limit = 10;
expander('Advanced options', function () use (&$limit): void {
    $limit = number('Limit', default: 10, min: 1, max: 100);

    if (button('Check')) {
        success('Checked with a limit of ' . $limit . '.');
    }
});

expander('About this tool', function (): void {
    note('It only exists for the tests.');
}, open: true);

note('Owner: ' . ($owner === '' ? 'nobody' : $owner) . '. Limit: ' . $limit . '.');
