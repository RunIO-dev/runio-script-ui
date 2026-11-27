<?php
// Test: choices in Arabic
// The same groups on a page that runs from right to left.

use function Runio\ScriptUi\{title, radio, multiselect, note};

title('اختبار: الخيارات');

$status = radio('حالة الطلب', ['paid' => 'مدفوع', 'shipped' => 'تم الشحن', 'refunded' => 'مُسترجَع'], horizontal: true);
$cities = multiselect(
    'المدن',
    ['عمّان', 'القاهرة', 'الرياض', 'دبي', 'بيروت', 'بغداد', 'الدوحة', 'تونس', 'الرباط', 'London'],
    default: ['عمّان'],
    help: 'اختر مدينة أو أكثر.',
);
$empty = multiselect('الوسوم', []);

note('الحالة: ' . $status . '. المدن: ' . ($cities === [] ? 'لا شيء' : implode('، ', $cities)) . '.');
