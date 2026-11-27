<?php
// Test: field errors
// A form that says under each field what is wrong with it, for the browser tests.

use function Runio\ScriptUi\{title, select, text, number, radio, multiselect, checkbox, expander, tabs, file_input, button, success, note, field_error, valid};

title('Test: field errors');

// "on save" checks behind the button only. "while typing" also checks the email and the report on every run.
$when = select('Check', ['on save', 'while typing']);

$name = text('Name', help: 'As on the invoice.');
$email = text('Email');
$amount = number('Amount');
$plan = radio('Plan', ['Free', 'Team', 'Company']);
$days = multiselect('Days', ['Mon', 'Tue', 'Wed']);
$terms = checkbox('I accept the terms');

$street = '';
expander('Delivery', function () use (&$street): void {
    $street = text('Street');
});

$vat = '';
tabs([
    'Contact' => function (): void {
        text('Phone');
    },
    'Billing' => function () use (&$vat): void {
        $vat = text('VAT number');
    },
]);

$report = file_input('Report', maxSize: 1024);

$emailIsWrong = !str_contains($email, '@');
$reportIsWrong = $report !== null && !str_ends_with($report->name, '.csv');
if ($when === 'while typing') {
    if ($email !== '' && $emailIsWrong) {
        field_error('Email', 'Enter an email address.');
    }
    if ($reportIsWrong) {
        field_error('Report', 'The report has to be a CSV file.');
    }
}

if (button('Save', style: 'primary')) {
    if ($name === '') {
        field_error('Name', 'Enter a name.');
    }
    if ($emailIsWrong) {
        // Said above already when the email is checked while typing: it is shown once.
        field_error('Email', 'Enter an email address.');
    }
    if ($amount === null || $amount <= 0) {
        field_error('Amount', 'Enter an amount above 0.');
    }
    if ($plan === 'Company') {
        field_error('Plan', 'The Company plan is sold out.');
    }
    if ($days === []) {
        field_error('Days', 'Tick at least one day.');
    }
    if (!$terms) {
        field_error('I accept the terms', 'Accept the terms to go on.');
    }
    if ($street === '') {
        field_error('Street', 'Enter a street.');
    }
    if ($vat === '') {
        field_error('VAT number', 'Enter a VAT number.');
    }
    if ($reportIsWrong) {
        field_error('Report', 'The report has to be a CSV file.');
    }

    if (valid()) {
        success('Saved for ' . $name . '.');
    }
}

note('Checked ' . $when . '.');
