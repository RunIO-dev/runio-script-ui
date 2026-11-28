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
    'index.none_allowed' => 'لا توجد هنا أداة يُسمح لك بفتحها.',
    'index.in_web_root' => 'مجلد الأدوات {folder} يقع داخل جذر الموقع (web root). يستطيع خادم الويب تشغيل ملف أداة باسمه مباشرةً، وعندها لا يُسأل التحقق من تسجيل الدخول ولا التحقق من الصلاحية في serve(). انقل المجلد إلى خارج جذر الموقع أو أغلقه في إعدادات خادم الويب. ترى هذه الملاحظة لأن تفاصيل الأخطاء معروضة هنا.',

    // The question a button asks first
    'confirm.title' => 'يرجى التأكيد',
    'confirm.yes' => 'نعم',
    'confirm.no' => 'إلغاء',

    // A page that runs again by itself
    'refresh.updated' => 'آخر تحديث {time}',
    'refresh.paused' => 'متوقف',
    'refresh.pause' => 'إيقاف مؤقت',
    'refresh.resume' => 'متابعة',

    // A task that takes long
    'progress.percent' => '{percent}%',

    // Tables
    'table.empty' => 'لا توجد صفوف.',
    'table.select_page' => 'تحديد كل صفوف هذه الصفحة',
    'table.clear' => 'إلغاء التحديد',
    'table.select_row' => 'تحديد الصف {row}',
    'table.search' => 'بحث',
    'table.search_label' => 'البحث في هذا الجدول',
    'table.no_match' => 'لا توجد صفوف مطابقة للبحث.',
    'table.rows_one' => 'صف واحد',
    'table.rows' => 'الصفوف: {count}',
    'table.range' => 'الصفوف {from} إلى {to} من {count}',
    'table.range_open' => 'الصفوف {from} إلى {to}',
    'table.filtered' => '{rows} (من أصل {count})',
    'table.selected' => 'المحدد: {count}',
    'table.selected_elsewhere' => 'المحدد: {count} (منها {hidden} في صفحات أخرى)',
    'table.selected_hidden' => 'المحدد المخفي بسبب البحث: {count}',
    'table.selected_max' => 'أقصى عدد يمكن تحديده: {count}',
    'table.selected_page' => 'المحدد في هذه الصفحة: {count}',
    'table.unselected_one' => 'أُلغي تحديد صف واحد لأنه ليس في هذه الصفحة.',
    'table.unselected' => 'أُلغي تحديد {count} من الصفوف لأنها ليست في هذه الصفحة.',
    'table.pages' => 'الصفحات',
    'table.page' => 'الصفحة {page} من {pages}',
    'table.page_open' => 'الصفحة {page}',
    'table.previous' => 'السابق',
    'table.next' => 'التالي',
    'cell.yes' => 'نعم',
    'cell.no' => 'لا',
    'number.thousands' => ',',
    'number.decimal' => '.',

    // Choices
    'choices.none' => 'لا توجد خيارات.',

    // What is wrong with a field. Read out in front of the message, and not shown.
    'field.error' => 'خطأ:',

    // الملفات
    'file.remove' => 'إزالة',
    'file.remove_label' => 'إزالة {name}',
    'file.types' => 'المسموح: {types}.',
    'file.limit' => 'حتى {size}.',
    'file.too_large' => '«{name}» أكبر من {size}.',
    'file.wrong_type' => '«{name}» غير مسموح هنا. المسموح: {types}.',
    'file.too_many' => 'لا يتسع هذا الحقل لأكثر من {count} ملفاً.',
    'file.failed' => 'لم يصل «{name}». حاول مرة أخرى.',
    'file.gone' => 'ملف كان هنا لم يعد موجوداً. اختره مرة أخرى.',
    'size.b' => '{n} بايت',
    'size.kb' => '{n} كيلوبايت',
    'size.mb' => '{n} ميغابايت',
    'size.gb' => '{n} غيغابايت',

    // Charts
    'chart.empty' => 'لا توجد بيانات للرسم.',
    'chart.data' => 'البيانات',
    'chart.rows_of' => 'الصفوف: {shown} من {count}',
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
    'error.tool_denied_text' => 'لا يُسمح لك بفتح هذه الأداة.',
    'error.access_failed' => 'فشل التحقق من الصلاحية',
    'error.access_failed_text' => 'لن يُسمح لأحد بالدخول حتى يعمل التحقق من جديد. التفاصيل في سجل أخطاء الخادم.',
    'error.method' => 'الطريقة غير مسموحة',
    'error.method_text' => 'هذه الصفحة تستجيب لطلبات GET و POST.',
    'error.expired' => 'انتهت صلاحية هذه الصفحة',
    'error.expired_text' => 'انتهت جلستك، أو لم يُرسل النموذج من هذه الصفحة. افتح الصفحة من جديد وكرر ما فعلته.',
    'error.expired_link' => 'فتح الصفحة من جديد',
    'error.too_large' => 'الحجم كبير جداً',
    'error.too_large_text' => 'هذا أكثر مما يقبله الخادم في مرة واحدة. اختر ملفاً أصغر أو عدداً أقل من الملفات.',
    'error.too_large_link' => 'العودة إلى الصفحة',
];
