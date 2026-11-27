<?php
// Test: tabs
// Parts of a page behind headers, for the browser tests.

use function Runio\ScriptUi\{title, text, number, select, note, tabs, button, success};

title('Test: tabs');

$owner = text('Owner');

$status = 'all';
$limit = 10;
tabs([
    'Orders' => function () use (&$status): void {
        $status = select('Status', ['all', 'paid', 'refunded']);
        note('Orders with the status: ' . $status . '.');
    },
    'Settings' => function () use (&$limit): void {
        $limit = number('Limit', default: 10, min: 1, max: 100);

        if (button('Check')) {
            success('Checked with a limit of ' . $limit . '.');
        }
    },
    'About' => function (): void {
        note('It only exists for the tests.');

        // Tabs in a tab: a line of headers that is out of sight at first.
        $years = [];
        foreach (range(2015, 2026) as $year) {
            $years[$year] = static function () use ($year): void {
                note('The numbers of ' . $year . '.');
            };
        }
        tabs($years, key: 'years');
    },
]);

note('Owner: ' . ($owner === '' ? 'nobody' : $owner) . '. Status: ' . $status . '. Limit: ' . $limit . '.');

// More headers than a phone is wide.
$months = [];
foreach (['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August'] as $month) {
    $months[$month] = static function () use ($month): void {
        note('The numbers of ' . $month . '.');
    };
}
tabs($months, key: 'months');
