<?php

declare(strict_types=1);

/**
 * The built-in texts in Arabic. The ids and their meaning are in en.php.
 *
 * Counts are written as "name: number" ("الصفوف: 5"), which is right for every
 * number. Arabic has six plural forms and the library has none.
 */

// Code inside an Arabic sentence is wrapped in marks that keep it left to right.
// Without them "auth:" is shown as ":auth" and "serve()" as "()serve".
$code = static fn (string $text): string => "\u{2066}" . $text . "\u{2069}";

return [
    // The page around a tool
    'index.title' => 'الأدوات',
    'page.all_tools' => 'كل الأدوات',
    'page.reset' => 'إعادة تعيين',
    'page.reset_hint' => 'حذف المدخلات المحفوظة لهذه الأداة',
    'page.update' => 'تحديث',
    'page.offline' => 'لم يستجب الخادم. تأكد من أنه يعمل ثم حاول مرة أخرى. إذا انتهت جلستك فأعد تحميل الصفحة.',
    'index.empty' => 'لا توجد أدوات بعد. ضع ملف PHP في {folder} وسيظهر هنا.',

    // The question a button asks first
    'confirm.title' => 'يرجى التأكيد',
    'confirm.yes' => 'نعم',
    'confirm.no' => 'إلغاء',

    // Tables
    'table.empty' => 'لا توجد صفوف.',
    'table.select' => 'تحديد',
    'table.select_row' => 'تحديد الصف {row}',
    'table.rows_one' => 'صف واحد',
    'table.rows' => 'الصفوف: {count}',
    'table.rows_of' => 'الصفوف: {shown} من {count}',
    'table.selected' => 'المحدد: {count}',
    'cell.yes' => 'نعم',
    'cell.no' => 'لا',
    'number.thousands' => ',',

    // Charts
    'chart.empty' => 'لا توجد بيانات للرسم.',
    'chart.data' => 'البيانات',
    'chart.bar' => 'مخطط أعمدة: {series} حسب {axis}',
    'chart.line' => 'مخطط خطي: {series} حسب {axis}',

    // A tool that failed
    'failure.text' => 'حدث خطأ أثناء تشغيل هذه الأداة. التفاصيل في سجل أخطاء الخادم.',
    'failure.trace' => 'تتبّع الخطأ',

    // Pages that only say something
    'error.not_found' => 'غير موجود',
    'error.no_address' => 'لا يوجد شيء في هذا العنوان.',
    'error.no_tool' => 'لا توجد أداة في هذا العنوان.',
    'error.local_only' => 'متاح على هذا الحاسوب فقط',
    'error.local_only_text' => 'لا يوجد تحقق من تسجيل الدخول، لذلك لا تستجيب هذه الأدوات إلا لمتصفح على الحاسوب الذي تعمل عليه (' . $code('http://localhost') . ')، وليس عبر proxy أو tunnel.' . "\n\n"
        . 'لاستخدامها من أي مكان آخر أضف تحققاً من تسجيل الدخول: مرِّر ' . $code('auth:') . ' إلى ' . $code('Runio\ScriptUi\serve()') . ' أو إلى ' . $code('Runio\ScriptUi\configure()') . '. '
        . 'يستقبل الطلب ويعيد اسم المستخدم، أو null للرفض.',
    'error.login_failed' => 'فشل التحقق من تسجيل الدخول',
    'error.login_failed_text' => 'لن يُسمح لأحد بالدخول حتى يعمل التحقق من جديد. التفاصيل في سجل أخطاء الخادم.',
    'error.denied' => 'غير مسموح',
    'error.denied_text' => 'ليست لديك صلاحية الوصول إلى هذه الصفحة.',
    'error.method' => 'الطريقة غير مسموحة',
    'error.method_text' => 'هذه الصفحة تستجيب لطلبات GET و POST.',
    'error.expired' => 'انتهت صلاحية هذه الصفحة',
    'error.expired_text' => 'انتهت جلستك، أو لم يُرسل النموذج من هذه الصفحة. افتح الصفحة من جديد وكرر ما فعلته.',
    'error.expired_link' => 'فتح الصفحة من جديد',
];
