<?php
// Test: field errors in Arabic
// The same notes on a page that runs from right to left.

use function Runio\ScriptUi\{title, text, radio, button, success, field_error, valid};

title('اختبار: أخطاء الحقول');

$name = text('الاسم');
$city = radio('المدينة', ['عمّان', 'القاهرة', 'الرياض']);

if (button('حفظ', style: 'primary')) {
    if ($name === '') {
        field_error('الاسم', 'اكتب الاسم.');
    }
    if ($city === 'الرياض') {
        field_error('المدينة', 'لا نوصّل إلى الرياض بعد.');
    }

    if (valid()) {
        success('تم الحفظ.');
    }
}
