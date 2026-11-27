<?php
// Test: choices
// Radio buttons and tick boxes in every layout, for the browser tests.

use function Runio\ScriptUi\{title, radio, multiselect, note, text, button, success, table};

title('Test: choices');

$owner = text('Owner');

$plan = radio(
    'Plan',
    ['free' => 'Free', 'team' => 'Team', 'business' => 'Business', 'enterprise' => 'Enterprise, with a contract and one invoice at the end of every month'],
    help: 'The arrow keys walk through the plans.',
);
$billing = radio('Billing', [1 => 'Monthly', 12 => 'Yearly'], default: 12, horizontal: true);

$months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$picked = multiselect('Months', $months, default: ['March'], help: 'Tick the months that count.');
$tags = multiselect('Tags', ['new' => 'New customer', 'vip' => 'VIP', 'late' => 'Pays late'], horizontal: true);

note(sprintf(
    'Owner: %s. Plan: %s. Billing: %d. Months: %s. Tags: %s.',
    $owner === '' ? 'nobody' : $owner,
    $plan,
    $billing,
    $picked === [] ? 'none' : implode(', ', $picked),
    $tags === [] ? 'none' : implode(', ', $tags),
));

// A table below the fields: its page is kept while the choices stay as they are.
table(
    array_map(static fn (int $n): array => ['id' => $n, 'plan' => $plan, 'month' => $months[$n % 12]], range(1, 30)),
    pageSize: 10,
);

if (button('Save')) {
    success('Saved ' . $plan . ' for ' . count($picked) . ' of 12 months.');
}
