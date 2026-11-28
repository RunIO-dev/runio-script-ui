<?php

require_once __DIR__ . '/_settings.php';

use function Runio\ScriptUi\{title, note, user};

title('Open to all');
note('Read by ' . user() . '.');
