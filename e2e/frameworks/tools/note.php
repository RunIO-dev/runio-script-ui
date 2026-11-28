<?php
// Keep a note

use function Runio\ScriptUi\{title, text, button, success, note, user};

title('Keep a note');
$text = text('Note');

if (button('Save')) {
    success('Saved "' . $text . '" for ' . user() . '.');
}

// A variable from the application.
note('From ' . $app . '.');
