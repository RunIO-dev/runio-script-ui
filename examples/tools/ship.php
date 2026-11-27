<?php
// Ship orders
// Search the paid orders or go through them page by page, tick the ones that left the warehouse, confirm.

require_once __DIR__ . '/_shop.php';

use function Runio\ScriptUi\{title, table, button, success, info, rerun};

$shop = Shop::open();

title('Ship orders');

$orders = $shop->orders(status: 'paid');

if ($orders === []) {
    info('No paid order is waiting.');

    return;
}

// All paid orders go in. The table shows ten at a time and searches all of them.
// A tick stays when the page changes. A ticked row that the search hides is not
// in $picked, and it is ticked again when the search shows it.
$picked = table(
    $orders,
    selectable: true,
    columns: ['id' => 'Order', 'created_at' => 'Placed on', 'customer' => 'Customer', 'items' => 'Items', 'total' => 'Total'],
    pageSize: 10,
    search: true,
);

$count = count($picked) === 1 ? '1 order' : count($picked) . ' orders';

if ($picked && button('Mark as shipped', style: 'primary', confirm: 'Mark ' . $count . ' as shipped?')) {
    foreach ($picked as $order) {
        $shop->ship($order['id']);
    }

    success('Marked ' . $count . ' as shipped.');
    rerun();
}
