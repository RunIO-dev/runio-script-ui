<?php
// Sales report
// Ava and Ben may open this one.

use function Runio\ScriptUi\{title, refresh, note, user};

title('Sales report');
// A page that stays open on a screen: it runs again by itself.
refresh(5);
note('Read by ' . user() . '.');
