<?php
// Refund orders
// Find a customer's orders, tick the ones to refund, confirm.

require_once __DIR__ . '/_shop.php';

use function Runio\ScriptUi\{title, text, select, table, button, success, info, rerun};

$shop = Shop::open();

title('Refund orders');

$email = text('Customer email', placeholder: 'ava@example.com', help: 'Try ava@example.com or omar@example.com.');
$status = select('Status', ['paid', 'shipped']);

if ($email === '') {
    info('Type a customer email to see their orders.');

    return;
}

$orders = $shop->orders(email: $email, status: $status);

$picked = table(
    $orders,
    selectable: true,
    columns: ['id' => 'Order', 'created_at' => 'Placed on', 'items' => 'Items', 'total' => 'Total'],
    empty: 'No ' . $status . ' orders for ' . $email . '.',
);

$count = count($picked) === 1 ? '1 order' : count($picked) . ' orders';

if ($picked && button('Refund selected', style: 'danger', confirm: 'Refund ' . $count . '?')) {
    foreach ($picked as $order) {
        $shop->refund($order['id']);
    }

    success('Refunded ' . $count . '.');
    rerun();
}
