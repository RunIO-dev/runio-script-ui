<?php
// Hello
// My first tool.

use function Runio\ScriptUi\{title, text, button, success};

title('Hello');
$name = text('Your name');

if (button('Greet') && $name !== '') {
    success('Hello, ' . $name . '.');
}
