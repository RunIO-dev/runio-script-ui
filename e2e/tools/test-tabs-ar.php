<?php
// Test: tabs in Arabic
// The same headers on a page that runs from right to left.

use function Runio\ScriptUi\{title, note, tabs};

title('اختبار: التبويبات');

tabs([
    'الطلبات' => static fn () => note('هنا الطلبات.'),
    'العملاء' => static fn () => note('هنا العملاء.'),
    'الإعدادات' => static fn () => note('هنا الإعدادات.'),
]);

// More headers than a phone is wide. Here the ones that do not fit are cut off on the left.
$months = [];
foreach (['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس'] as $month) {
    $months[$month] = static function () use ($month): void {
        note('أرقام شهر ' . $month . '.');
    };
}
tabs($months, key: 'months');
