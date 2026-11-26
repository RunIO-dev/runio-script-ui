<?php
// Sales dashboard
// Revenue, orders and refunds for the last days.

require_once __DIR__ . '/_shop.php';

use function Runio\ScriptUi\{title, heading, select, columns, metric, chart, table};

$shop = Shop::open();

title('Sales dashboard');

$days = select('Period', [7 => 'Last 7 days', 14 => 'Last 14 days', 30 => 'Last 30 days'], default: 14);

$now = $shop->daily($days);
$before = array_slice($shop->daily($days * 2), 0, $days);

$sum = static fn (array $rows, string $column): float => (float) array_sum(array_column($rows, $column));
$change = static function (float $now, float $before): ?string {
    if ($before == 0.0) {
        return null;
    }

    return sprintf('%+.1f%%', ($now - $before) / $before * 100);
};

$revenue = $sum($now, 'revenue');
$orders = $sum($now, 'orders');
$refunds = $sum($now, 'refunds');

columns(
    fn () => metric('Revenue', '$' . number_format($revenue), $change($revenue, $sum($before, 'revenue'))),
    fn () => metric('Orders', number_format($orders), $change($orders, $sum($before, 'orders'))),
    fn () => metric('Average order', '$' . number_format($orders > 0 ? $revenue / $orders : 0, 2)),
    fn () => metric('Refunds', number_format($refunds), $change($refunds, $sum($before, 'refunds')), inverse: true),
);

chart($now, x: 'day', y: 'revenue', title: 'Revenue per day (USD)');
chart($now, x: 'day', y: ['orders' => 'Orders', 'refunds' => 'Refunds'], type: 'line', title: 'Orders and refunds per day');

heading('Latest orders');
table(
    $shop->orders(days: $days),
    columns: ['id' => 'Order', 'created_at' => 'Placed on', 'customer' => 'Customer', 'items' => 'Items', 'status' => 'Status', 'total' => 'Total'],
    limit: 15,
);
