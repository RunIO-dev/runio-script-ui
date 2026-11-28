<?php
// Refund orders
// Ava may open this one.

use function Runio\ScriptUi\{title, text, button, success, user};

title('Refund orders');
$order = text('Order');

if (button('Refund')) {
    success('Refunded ' . $order . ' for ' . user() . ' at the ' . $desk . '.');
}
