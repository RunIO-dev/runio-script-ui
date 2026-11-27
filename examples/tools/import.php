<?php
// Import orders
// Upload a CSV file with new orders, look at what it holds, then add them to the shop.

require_once __DIR__ . '/_shop.php';

use function Runio\ScriptUi\{title, file_input, table, button, download, success, warning, error, rerun};

$shop = Shop::open();

title('Import orders');

$file = file_input(
    'Orders as CSV',
    types: ['csv'],
    maxSize: 1024 * 1024,
    help: 'One order per line. The first line names the columns: email, items, total.',
);

if ($file === null) {
    // Something to try the tool with.
    download('Download an example file', Shop::exampleOrders(), 'orders-example.csv');

    return;
}

// The file waits on the server while you look at it. Nothing is in the shop yet.
[$orders, $problems] = $shop->readOrders($file->path);

if ($problems !== []) {
    $more = count($problems) - 5;
    warning(implode("\n", array_slice($problems, 0, 5)) . ($more > 0 ? "\nAnd " . $more . ' more.' : ''));
}

if ($orders === []) {
    error('No order could be read from ' . $file->name . '.');

    return;
}

table($orders, columns: ['customer' => 'Customer', 'email' => 'Email', 'items' => 'Items', 'total' => 'Total'], pageSize: 10);

$count = count($orders) === 1 ? '1 order' : count($orders) . ' orders';

if (button('Import ' . $count, style: 'primary', confirm: 'Add ' . $count . ' to the shop?')) {
    $shop->addOrders($orders);

    // The tool is done with the file. Without this line it would wait on the server for a day.
    $file->forget();

    success('Added ' . $count . ' from ' . $file->name . '.');
    rerun();
}
