<?php
// Order archive
// 100,000 orders, read one page at a time.

require_once __DIR__ . '/_archive.php';

use function Runio\ScriptUi\{title, note, table, button, success, warning};

title('Order archive');
note('This table holds one page. Each time it is turned, searched or sorted, it asks for the rows of the page it shows, and for nothing else.');

$picked = table(
    Archive::page(...),      // gets a TableQuery: the search, the order, a limit and an offset
    selectable: true,
    columns: ['id' => 'Order', 'customer' => 'Customer', 'placed' => 'Placed on', 'status' => 'Status', 'total' => 'Total'],
    search: true,
    sortable: ['id', 'customer', 'placed', 'total'],
    count: Archive::count(...),   // gets the search, says how many orders it leaves
);

if (button('Print labels')) {
    if ($picked === []) {
        warning('Tick the orders that need a label first.');
    } else {
        success('Labels are on their way for ' . implode(', ', array_column($picked, 'id')) . '.');
    }
}
