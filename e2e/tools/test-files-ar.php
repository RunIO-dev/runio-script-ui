<?php
// Test: a file field in Arabic
// Only for the browser tests: the title is Arabic, so the texts of the field are too.

use function Runio\ScriptUi\{title, file_input};

title('رفع تقرير');

file_input('التقرير', types: ['pdf'], maxSize: 1536, key: 'report');
