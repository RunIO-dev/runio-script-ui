<?php
// البحث في الطلبات
// صفحة بالعربية: العنوان العربي يجعل اتجاه الصفحة كلها من اليمين إلى اليسار.

require_once __DIR__ . '/_shop.php';

use function Runio\ScriptUi\{title, columns, text, select, metric, table};

$shop = Shop::open();

title('البحث في الطلبات');

$email = $status = null;

columns(
    function () use (&$email): void {
        $email = text('البريد الإلكتروني للعميل', placeholder: 'omar@example.com');
    },
    function () use (&$status): void {
        $status = select('حالة الطلب', ['' => 'كل الحالات', 'paid' => 'مدفوع', 'shipped' => 'تم الشحن', 'refunded' => 'مُسترجَع']);
    },
);

$orders = $shop->orders(email: $email === '' ? null : $email, status: $status === '' ? null : $status);

columns(
    fn () => metric('عدد الطلبات', number_format(count($orders))),
    fn () => metric('الإجمالي', '$' . number_format(array_sum(array_column($orders, 'total')), 2)),
);

table(
    $orders,
    columns: ['id' => 'رقم الطلب', 'created_at' => 'التاريخ', 'customer' => 'العميل', 'items' => 'المنتجات', 'total' => 'المبلغ'],
    limit: 20,
    empty: 'لا توجد طلبات مطابقة.',
);
