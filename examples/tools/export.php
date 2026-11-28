<?php
// Export orders
// A task that takes long: start it, watch how far it is, stop it, fetch the file.

require_once __DIR__ . '/_export.php';

use function Runio\ScriptUi\{title, note, progress, refresh, button, download, success, warning, rerun};

title('Export orders');

$export = Export::read();   // the export belongs to the shop: whoever opens this page sees the same one

if ($export->state === 'waiting' || $export->state === 'running') {
    // The work goes on somewhere else. This page looks at it every two seconds, for as long as it is open.
    refresh(2);

    if ($export->state === 'waiting') {
        // No value: nobody knows yet how far it is.
        progress('Waiting for a worker');
    } else {
        progress('Exporting orders', $export->done, Export::ORDERS, text: number_format($export->done) . ' of ' . number_format(Export::ORDERS) . ' orders');
    }

    if (button('Stop the export', confirm: 'Stop the export where it is?')) {
        Export::stop();
        rerun();
    }

    return;
}

if ($export->state === 'finished') {
    success(number_format($export->done) . ' orders are exported.');
    download('Download orders.csv', Export::orders(...), 'orders.csv');
} elseif ($export->state === 'stopped') {
    warning('The export was stopped at ' . number_format($export->done) . ' of ' . number_format(Export::ORDERS) . ' orders.');
} else {
    note('An export of all orders takes about half a minute. In a real shop a worker would write it. Here the clock stands in for the worker.');
}

if (button($export->state === 'none' ? 'Start the export' : 'Start a new export', style: 'primary')) {
    // Hands the work over and comes back at once. No message: the bar on the next page says that it started.
    Export::start();
    rerun();
}
