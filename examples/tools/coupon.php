<?php
// Give a coupon
// A form that checks what was typed, and says under each field what is wrong with it.

require_once __DIR__ . '/_shop.php';

use function Runio\ScriptUi\{title, heading, text, number, date_input, textarea, button, field_error, valid, success, table};

$shop = Shop::open();

title('Give a coupon');

$email = text('Customer email', placeholder: 'ava@example.com', help: 'Try ava@example.com or omar@example.com.');
$percent = number('Discount in percent', default: 10, min: 1, max: 50, step: 1);
$until = date_input('Valid until', default: date('Y-m-d', strtotime('+30 days')), min: date('Y-m-d'));
$reason = textarea('Reason', rows: 2, help: 'It goes to the log. More than 20 percent needs one.');

$customer = $shop->customer($email);
$coupons = $customer === null ? [] : $shop->coupons($customer['email']);

// Checked on every run: the note comes when the user leaves the field, and it
// stays until the field is right. An empty field is left alone here, or a new
// page would start full of red.
if ($email !== '' && $customer === null) {
    field_error('Customer email', 'No customer has this email.');
}

if (button('Give coupon', style: 'primary')) {
    // Checked when the button is pressed: what is missing, and what only counts now.
    if ($email === '') {
        field_error('Customer email', 'Enter the email of a customer.');
    }
    if ($percent === null) {
        field_error('Discount in percent', 'Enter a discount from 1 to 50.');
    }
    if ($until === null) {
        field_error('Valid until', 'Choose the last day of the coupon.');
    }
    if ($percent > 20 && mb_strlen($reason) < 10) {
        field_error('Reason', 'More than 20 percent needs a reason. Write a sentence.');
    }
    // The form still holds what was typed after the coupon was given. A second
    // press, or a double click, must not give a second one. Two requests at the
    // very same moment would both pass this check: where it matters, let the
    // database refuse the second one, with a unique key.
    foreach ($coupons as $coupon) {
        if ($coupon['until'] >= date('Y-m-d')) {
            field_error('Customer email', $customer['name'] . ' has a coupon already: ' . $coupon['code'] . ', valid until ' . $coupon['until'] . '.');

            break;
        }
    }

    // field_error() does not stop the script. Nothing is done until all of it is right.
    if (valid()) {
        $code = $shop->addCoupon($customer['email'], $percent, $until);
        $coupons = $shop->coupons($customer['email']);
        success($customer['name'] . ' got ' . $percent . ' % off with the code ' . $code . ', until ' . $until . '.');
    }
}

if ($customer !== null) {
    heading('Coupons of ' . $customer['name']);
    table(
        $coupons,
        columns: ['code' => 'Code', 'percent' => 'Percent', 'until' => 'Valid until', 'given' => 'Given on'],
        empty: 'No coupon yet.',
    );
}
