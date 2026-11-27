<?php
// Test: refresh
// A page that runs again by itself, for the browser tests.

use function Runio\ScriptUi\{title, select, text, date_input, note, button, success, file_input, refresh};

title('Test: refresh');

// Every run has a number, so that a test can tell one run from the next.
$file = __DIR__ . '/../var/test-refresh.txt';
is_dir(dirname($file)) || mkdir(dirname($file), 0777, true);
$run = (int) @file_get_contents($file) + 1;
file_put_contents($file, (string) $run, LOCK_EX);

$rate = select('Rate', ['5 seconds', 'for ever', '10 minutes', 'off']);
if ($rate === '5 seconds') {
    refresh(5, stopAfter: 60);
} elseif ($rate === 'for ever') {
    refresh(5, stopAfter: null);
} elseif ($rate === '10 minutes') {
    refresh(600, stopAfter: null);
}

$owner = text('Owner');
date_input('Day');
note('Run ' . $run . '. Owner: ' . ($owner === '' ? 'nobody' : $owner) . '.');

if (button('Retry')) {
    success('Retried in run ' . $run . '.');
}

if (button('Delete', confirm: 'Delete everything?')) {
    success('Deleted in run ' . $run . '.');
}

file_input('Report', maxSize: 1024);
