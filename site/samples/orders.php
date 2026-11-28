<?php
// Find orders
// Filter a list, and download what is left.

use function Runio\ScriptUi\{title, text, select, note, table, download};

// In a real tool these rows come from your database.
$orders = [
    ['id' => 1041, 'customer' => 'Ava Morgan', 'status' => 'paid', 'total' => 84.50],
    ['id' => 1042, 'customer' => 'Omar Haddad', 'status' => 'shipped', 'total' => 19.90],
    ['id' => 1043, 'customer' => 'Lina Saleh', 'status' => 'paid', 'total' => 240.00],
    ['id' => 1044, 'customer' => 'Noah Becker', 'status' => 'refunded', 'total' => 52.25],
    ['id' => 1045, 'customer' => 'Omar Haddad', 'status' => 'paid', 'total' => 132.10],
    ['id' => 1046, 'customer' => 'Mia Rossi', 'status' => 'shipped', 'total' => 61.00],
];

title('Find orders');

$name = text('Customer', placeholder: 'Part of a name');
$status = select('Status', ['any', 'paid', 'shipped', 'refunded']);

$found = array_values(array_filter($orders, fn (array $order): bool
    => ($name === '' || stripos($order['customer'], $name) !== false)
    && ($status === 'any' || $order['status'] === $status)));

note(count($found) . ' of ' . count($orders) . ' orders.');
table($found, columns: ['id' => 'Order', 'customer' => 'Customer', 'status' => 'Status', 'total' => 'Total']);

if ($found !== []) {
    download('Download as CSV', $found, 'orders.csv');
}
