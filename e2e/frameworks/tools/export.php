<?php
// Export a list

use function Runio\ScriptUi\{title, download};

title('Export a list');
download('Download as CSV', [['name' => 'Ava', 'city' => 'Lyon'], ['name' => 'Ben', 'city' => 'Graz']], 'people.csv');
