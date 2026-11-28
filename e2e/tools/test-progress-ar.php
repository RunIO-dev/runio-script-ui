<?php
// Test: progress in Arabic
// A page that reads from right to left with a bar on it, for the browser tests.

use function Runio\ScriptUi\{title, progress};

title('اختبار: التقدم');

progress('استيراد الطلبات', 2, 5);
