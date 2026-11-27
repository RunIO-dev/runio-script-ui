<?php

declare(strict_types=1);

/**
 * The built-in texts in English. This file is the complete list: every other
 * language has the same ids, and a text that is missing somewhere is taken
 * from here.
 *
 * Words in {braces} are filled in by the library. Texts need no plural forms,
 * apart from the one row of a table that has exactly one row.
 */
return [
    // The page around a tool
    'index.title' => 'Tools',
    'page.all_tools' => 'All tools',
    'page.reset' => 'Reset',
    'page.reset_hint' => 'Forget the saved inputs of this tool',
    'page.update' => 'Update',
    'page.offline' => 'The server did not answer. Check that it is running, then try again. If you were logged out, reload the page.',
    'index.empty' => 'No tools yet. Put a PHP file into {folder} and it shows up here.',

    // The question a button asks first
    'confirm.title' => 'Please confirm',
    'confirm.yes' => 'Yes',
    'confirm.no' => 'Cancel',

    // Tables
    'table.empty' => 'No rows.',
    'table.select_page' => 'Select all rows on this page',
    'table.clear' => 'Clear selection',
    'table.select_row' => 'Select row {row}',
    'table.search' => 'Search',
    'table.search_label' => 'Search this table',
    'table.no_match' => 'No rows match the search.',
    'table.rows_one' => '1 row',
    'table.rows' => '{count} rows',
    'table.range' => 'Rows {from} to {to} of {count}',
    'table.filtered' => '{rows} (filtered from {count})',
    'table.selected' => '{count} selected',
    'table.selected_elsewhere' => '{count} selected ({hidden} on other pages)',
    'table.selected_hidden' => '{count} more hidden by the search',
    'table.selected_max' => 'No more than {count} rows can be selected.',
    'table.pages' => 'Pages',
    'table.page' => 'Page {page} of {pages}',
    'table.previous' => 'Previous',
    'table.next' => 'Next',
    'cell.yes' => 'Yes',
    'cell.no' => 'No',
    'number.thousands' => ',',
    'number.decimal' => '.',

    // Files
    'file.remove' => 'Remove',
    'file.remove_label' => 'Remove {name}',
    'file.types' => 'Allowed: {types}.',
    'file.limit' => 'Up to {size}.',
    'file.too_large' => '"{name}" is larger than {size}.',
    'file.wrong_type' => '"{name}" is not allowed here. Allowed: {types}.',
    'file.too_many' => 'No more than {count} files fit here.',
    'file.failed' => '"{name}" did not arrive. Please try again.',
    'file.gone' => 'A file that was here is gone. Please choose it again.',
    'size.b' => '{n} B',
    'size.kb' => '{n} KB',
    'size.mb' => '{n} MB',
    'size.gb' => '{n} GB',

    // Charts
    'chart.empty' => 'No data to draw.',
    'chart.data' => 'Data',
    'chart.rows_of' => 'Showing {shown} of {count} rows',
    'chart.bar' => 'Bar chart of {series} by {axis}',
    'chart.line' => 'Line chart of {series} by {axis}',

    // A tool that failed
    'failure.text' => 'Something went wrong while running this tool. The details are in the error log of the server.',
    'failure.trace' => 'Stack trace',

    // Pages that only say something
    'error.not_found' => 'Not found',
    'error.no_address' => 'There is nothing at this address.',
    'error.no_tool' => 'There is no tool at this address.',
    'error.local_only' => 'Only available on this computer',
    'error.local_only_text' => "No login check is set up, so these tools only answer to a browser on the computer they run on (http://localhost), and not through a proxy or a tunnel.\n\n"
        . 'To use them from anywhere else, add a login check: pass auth: to Runio\ScriptUi\serve() or to Runio\ScriptUi\configure(). '
        . 'It receives the request and returns the name of the user, or null to refuse.',
    'error.login_failed' => 'The login check failed',
    'error.login_failed_text' => 'Nobody is let in until it works again. The details are in the error log of the server.',
    'error.denied' => 'Not allowed',
    'error.denied_text' => 'You do not have access to this page.',
    'error.method' => 'Method not allowed',
    'error.method_text' => 'This page answers GET and POST requests.',
    'error.expired' => 'This page has expired',
    'error.expired_text' => 'Your session ended, or the form was not sent from this page. Open the page again and repeat what you did.',
    'error.expired_link' => 'Open the page again',
    'error.too_large' => 'Too large',
    'error.too_large_text' => 'This is more than the server takes at once. Choose a smaller file, or fewer files.',
    'error.too_large_link' => 'Back to the page',
];
