<?php
// Read what is not there
// A warning of PHP. A framework makes an error of it, and so it is one in a tool.

use function Runio\ScriptUi\{title, note};

title('Read what is not there');
note('Drawn before the warning.');

$list = [];
note('Value: ' . $list['missing']);
note('Drawn after the warning.');
