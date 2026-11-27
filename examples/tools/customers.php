<?php
// Customers
// Filter the customer list and look at one customer in detail.

require_once __DIR__ . '/_shop.php';

use function Runio\ScriptUi\{title, heading, columns, text, select, number, checkbox, date_input, table, download, json, link, note};

$shop = Shop::open();
$customers = $shop->customers();

title('Customers');

$search = $country = $minSpent = $joinedAfter = $refundsOnly = null;

columns(
    function () use (&$search): void {
        $search = text('Search', placeholder: 'Name or email');
    },
    function () use (&$country, $customers): void {
        $countries = array_unique(array_column($customers, 'country'));
        sort($countries);
        $country = select('Country', ['Any country', ...$countries]);
    },
    function () use (&$minSpent): void {
        $minSpent = number('Spent at least', default: 0, min: 0, step: 50);
    },
    function () use (&$joinedAfter): void {
        $joinedAfter = date_input('Joined after');
    },
);
$refundsOnly = checkbox('Only customers with a refund');

$found = array_values(array_filter($customers, static fn (array $customer): bool
    => ($search === '' || stripos($customer['name'] . ' ' . $customer['email'], $search) !== false)
    && ($country === 'Any country' || $customer['country'] === $country)
    && $customer['spent'] >= ($minSpent ?? 0)
    && ($joinedAfter === null || $customer['joined'] > $joinedAfter)
    && (!$refundsOnly || $customer['refunds'] > 0)));

$picked = table(
    $found,
    selectable: true,
    columns: ['name' => 'Name', 'email' => 'Email', 'country' => 'Country', 'joined' => 'Joined', 'orders' => 'Orders', 'refunds' => 'Refunds', 'spent' => 'Spent'],
    rowKey: 'email',   // a customer has no id column; the email tells them apart
    empty: 'No customer matches these filters.',
);

// The file holds what the filters above leave, with every column.
if ($found !== []) {
    download('Download as CSV', $found, 'customers.csv');
}

if (count($picked) === 1) {
    $customer = $picked[0];

    heading($customer['name']);
    link('Refund an order of this customer', '/refund?customer-email=' . rawurlencode($customer['email']));
    json($customer);
    table(
        $shop->orders(email: $customer['email']),
        columns: ['id' => 'Order', 'created_at' => 'Placed on', 'items' => 'Items', 'status' => 'Status', 'total' => 'Total'],
        key: 'orders',
        limit: 10,
    );
} elseif (count($picked) > 1) {
    note('Tick a single customer to see the details.');
}
