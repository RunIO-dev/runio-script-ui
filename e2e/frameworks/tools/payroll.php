<?php
// Payroll
// Open to one user only: the application says so in allow:.

use function Runio\ScriptUi\{title, note, user};

title('Payroll');
note('Read by ' . user() . '.');
