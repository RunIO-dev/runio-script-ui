<?php

declare(strict_types=1);

/**
 * The whole public interface: plain functions.
 *
 * A tool is a PHP script that calls them from top to bottom. Every time the
 * user changes an input or presses a button, the script runs again and each
 * input function returns the current value.
 *
 *     use function Runio\ScriptUi\{title, text, table, button, success};
 *
 *     title('Find orders');
 *     $email = text('Customer email');
 *     if ($email !== '') {
 *         table($orders->byEmail($email));
 *     }
 */

namespace Runio\ScriptUi;

// ------------------------------------------------------------------ display

/** The big heading of the page. The first one also names the browser tab. */
function title(string $text): void
{
    Runtime::page()->title($text);
}

/** A section heading. */
function heading(string $text): void
{
    Runtime::page()->heading($text);
}

/** A paragraph of plain text. Line breaks are kept. */
function note(string $text): void
{
    Runtime::page()->note($text);
}

/** Text in a fixed-width block, for code, SQL or log lines. */
function code(string $text): void
{
    Runtime::page()->code($text);
}

/** Any value as readable JSON. */
function json(mixed $value): void
{
    Runtime::page()->json($value);
}

/** A link. Only http, https and mailto addresses and paths on this site are accepted. */
function link(string $label, string $url, bool $newTab = false): void
{
    Runtime::page()->link($label, $url, $newTab);
}

/** A thin line between two parts of the page. */
function divider(): void
{
    Runtime::page()->divider();
}

/**
 * One number that matters, with an optional change such as "+4.2%".
 *
 * @param bool $inverse set it when going up is bad news (refunds, errors, churn)
 */
function metric(string $label, string|int|float $value, string|int|float|null $delta = null, bool $inverse = false): void
{
    Runtime::page()->metric($label, $value, $delta, $inverse);
}

function success(string $text): void
{
    Runtime::page()->callout('success', $text);
}

function info(string $text): void
{
    Runtime::page()->callout('info', $text);
}

function warning(string $text): void
{
    Runtime::page()->callout('warning', $text);
}

function error(string $text): void
{
    Runtime::page()->callout('error', $text);
}

// ------------------------------------------------------------------- inputs
//
// Every input takes an optional key. It names the form field and the saved
// value. By default it is made from the label. Two inputs or buttons with the
// same label on one page are an error until each has its own key, and so is a
// label that changes from run to run ("Amount (USD)", "Amount (EUR)"): give
// such an input a fixed key.

/** A single line of text. Returns what the user typed, trimmed. */
function text(string $label, string $default = '', ?string $placeholder = null, ?string $help = null, ?string $key = null): string
{
    return Runtime::page()->text($label, $default, $placeholder, $help, $key);
}

/** Several lines of text. */
function textarea(string $label, string $default = '', int $rows = 4, ?string $placeholder = null, ?string $help = null, ?string $key = null): string
{
    return Runtime::page()->textarea($label, $default, $rows, $placeholder, $help, $key);
}

/**
 * A number, kept between $min and $max. Returns null while the field is empty.
 * A whole number comes back as an int, anything else as a float.
 * With a whole-number $step the result is always a whole number.
 */
function number(
    string $label,
    int|float|null $default = null,
    int|float|null $min = null,
    int|float|null $max = null,
    int|float|null $step = null,
    ?string $help = null,
    ?string $key = null,
): int|float|null {
    return Runtime::page()->number($label, $default, $min, $max, $step, $help, $key);
}

/**
 * A choice from a list. Returns one of the options, never anything else.
 *
 * @param array<array-key,mixed> $options ['paid', 'shipped'] offers those values;
 *                                        [7 => 'Last 7 days', 30 => 'Last 30 days'] shows the texts and returns 7 or 30
 * @param bool|null              $byKey   PHP cannot tell [0 => 'No', 1 => 'Yes'] from the list ['No', 'Yes'].
 *                                        When the keys count up from 0 and are the values, say so with byKey: true
 */
function select(string $label, array $options, mixed $default = null, ?string $help = null, ?string $key = null, ?bool $byKey = null): mixed
{
    return Runtime::page()->select($label, $options, $default, $help, $key, $byKey);
}

/**
 * A choice from a short list, with every option in view. Returns one of the
 * options, never anything else: the first one until the user chooses another.
 *
 * It takes the options the way select() does. With more than a handful of
 * options, select() needs less room.
 *
 * @param array<array-key,mixed> $options    see select()
 * @param bool                   $horizontal the options stand side by side instead of one under the other
 * @param bool|null              $byKey      see select()
 */
function radio(
    string $label,
    array $options,
    mixed $default = null,
    bool $horizontal = false,
    ?string $help = null,
    ?string $key = null,
    ?bool $byKey = null,
): mixed {
    return Runtime::page()->radio($label, $options, $default, $horizontal, $help, $key, $byKey);
}

/**
 * Several choices from a list: a tick box for every option. Returns the ticked
 * options as a list, in the order of the options. Nothing ticked is an empty list.
 *
 * @param array<array-key,mixed> $options    see select()
 * @param array<array-key,mixed> $default    the options that are ticked until the user says otherwise
 * @param bool                   $horizontal the options stand side by side instead of one under the other
 * @param bool|null              $byKey      see select()
 *
 * @return list<mixed>
 */
function multiselect(
    string $label,
    array $options,
    array $default = [],
    bool $horizontal = false,
    ?string $help = null,
    ?string $key = null,
    ?bool $byKey = null,
): array {
    return Runtime::page()->multiselect($label, $options, $default, $horizontal, $help, $key, $byKey);
}

/** A tick box. */
function checkbox(string $label, bool $default = false, ?string $help = null, ?string $key = null): bool
{
    return Runtime::page()->checkbox($label, $default, $help, $key);
}

/** A date. Returns it as "YYYY-MM-DD", or null while the field is empty. */
function date_input(
    string $label,
    string|\DateTimeInterface|null $default = null,
    string|\DateTimeInterface|null $min = null,
    string|\DateTimeInterface|null $max = null,
    ?string $help = null,
    ?string $key = null,
): ?string {
    return Runtime::page()->date($label, $default, $min, $max, $help, $key);
}

/**
 * A field that takes a file from the user's computer.
 *
 * It returns the file once it is there, on every run, until the user removes
 * it or the tool forgets it:
 *
 *     $file = file_input('Orders', types: ['csv']);
 *     if ($file && button('Import')) {
 *         import($file->path);
 *         $file->forget();
 *     }
 *
 * The file lies in a folder that only PHP reads, for a day at most. Nothing
 * about it is trusted: its name is made safe, its content is whatever the user
 * sent, and its ending says nothing about what is in it.
 *
 * A field that is not drawn in a run gives its file up, as a text field loses
 * its text. That includes a run that ends above the field with stop() or an
 * early return.
 *
 * @param list<string>|null $types    the endings a file may have, such as ['csv', 'xlsx']. null takes every kind of file
 * @param bool              $multiple take several files, up to 20 and never more than PHP takes in one
 *                                    request (max_file_uploads in php.ini). The function then returns a list
 * @param int|null          $maxSize  the largest file in bytes. PHP's own limits (upload_max_filesize and
 *                                    post_max_size in php.ini) count as well, and the smaller one wins
 *
 * @return ($multiple is true ? list<Upload> : Upload|null) the file, or null while there is none.
 *                                                          With $multiple the files, in the order they arrived
 */
function file_input(
    string $label,
    ?array $types = null,
    bool $multiple = false,
    ?int $maxSize = null,
    ?string $help = null,
    ?string $key = null,
): Upload|array|null {
    return Runtime::page()->fileInput($label, $types, $multiple, $maxSize, $help, $key);
}

// --------------------------------------------------------------------- data

/**
 * A table of rows. Click a column header to sort. A long table is shown one page at a time.
 *
 * Pass all rows: the table sorts all of them and cuts them into pages itself.
 *
 * @param iterable<mixed>             $rows       arrays or objects, for example what a database query returned
 * @param bool                        $selectable adds a tick box to every row. A tick stays when the page of the table changes
 * @param array<array-key,mixed>|null $columns    ['id', 'total'] to choose columns, ['id' => 'Order'] to also rename them
 * @param string                      $rowKey     the column that identifies a row. A row without it is known by what is in it:
 *                                                its tick is dropped when the row changes
 * @param int                         $pageSize   how many rows one page shows, from 1 to 200
 * @param bool                        $search     adds a search box. It filters the rows while the user types and reads
 *                                                the columns that are shown. A ticked row that it hides is not returned
 * @param string|null                 $empty      what to say when there are no rows. By default "No rows." in the language of the page
 *
 * @return list<mixed> the rows the user ticked on all pages of the list, exactly as they were passed in (always empty unless $selectable)
 */
function table(
    iterable $rows,
    bool $selectable = false,
    ?array $columns = null,
    ?string $key = null,
    string $rowKey = 'id',
    int $pageSize = 25,
    bool $search = false,
    ?string $empty = null,
): array {
    return Runtime::page()->table($rows, $selectable, $columns, $key, $rowKey, $pageSize, $search, $empty);
}

/**
 * A bar or line chart of the rows.
 *
 * @param iterable<mixed>               $rows
 * @param string                        $x    the column with the labels along the bottom
 * @param string|array<array-key,mixed> $y    the column with the numbers; a list of columns draws several series,
 *                                            ['revenue' => 'Revenue (USD)'] also names them
 * @param string                        $type "bar" or "line"
 * @param bool                          $data also offer the numbers as a table under the chart
 */
function chart(
    iterable $rows,
    string $x,
    string|array $y,
    string $type = 'bar',
    ?string $title = null,
    int $height = 240,
    bool $data = true,
): void {
    Runtime::page()->chart($rows, $x, $y, $type, $title, $height, $data);
}

// ------------------------------------------------------------------ actions

/**
 * A button. Returns true only in the run that its click started, so
 * "if (button('Save')) { ... }" does the work exactly once per click.
 *
 * In a loop every button needs its own key, made from the row it belongs to:
 * button('Refund', key: 'refund-' . $order['id']).
 *
 * @param string      $style   "default", "primary" or "danger"
 * @param string|null $confirm a question to ask first. The button then returns true only after the user answered yes
 *                             to exactly this text. Keep it the same from run to run: with the time of day in it,
 *                             the answer would never fit the question
 * @param string|null $yes     the text of the answer that goes ahead. By default "Yes" in the language of the page
 * @param string|null $no      the text of the answer that does nothing. By default "Cancel" in the language of the page
 */
function button(
    string $label,
    string $style = 'default',
    ?string $confirm = null,
    ?string $yes = null,
    ?string $no = null,
    ?string $key = null,
): bool {
    return Runtime::page()->button($label, $style, $confirm, $yes, $no, $key);
}

/**
 * A button that hands the user a file.
 *
 *     download('Download CSV', $orders, 'orders.csv');
 *
 * Rows become a CSV file, or JSON when the name ends in ".json". Text is sent as
 * it is. A function is called only when the user asks for the file, so a slow
 * query does not run on every page view:
 *
 *     download('Export all', fn () => $db->fetchAll('SELECT * FROM orders'), 'orders.csv');
 *
 * The run ends where the file is made, as after stop(). A download is written to
 * the log like a pressed button, with the name and the size of the file. When the
 * log cannot be written (the callback throws or returns false), the file is not sent.
 *
 * The file is made in memory. No rows give an empty file: to still write the names
 * of the columns, pass csv($rows, columns: [...]).
 *
 * @param iterable<mixed>|string|\Closure $content rows, text, or a function that returns one of the two. A generator
 *                                                 can be read only once: when the page shows the same rows, pass
 *                                                 an array or a function
 * @param string                          $file    the name of the file, such as "orders.csv". It may contain what a
 *                                                 user typed: it is made safe. Its ending should come from you,
 *                                                 because the ending decides what a computer does with the file
 * @param string                          $style   "default", "primary" or "danger"
 */
function download(string $label, iterable|string|\Closure $content, string $file, string $style = 'default', ?string $key = null): void
{
    Runtime::page()->download($label, $content, $file, $style, $key);
}

/**
 * Rows as CSV text, for download() when the defaults do not fit:
 *
 *     download('Export', fn () => csv($orders, separator: ';'), 'orders.csv');
 *
 * @param iterable<mixed>             $rows
 * @param array<array-key,mixed>|null $columns       ['id', 'total'] to choose columns, ['id' => 'Order'] to also rename them.
 *                                                   By default every column, under its own name. For a very long export
 *                                                   name the columns: the rows are then written one by one and not kept
 * @param string                      $separator     what stands between two cells: ",", ";", "|", ":" or a tab.
 *                                                   Excel expects ";" where people write 1,5 for one and a half
 * @param bool                        $allowFormulas a spreadsheet runs a cell that starts with "=", "+", "-" or "@" as a
 *                                                   formula. By default such a cell gets a "'" in front, and numbers
 *                                                   are left alone. Pass true only when every cell comes from you
 * @param bool                        $bom           start the file with the mark that tells Excel the text is UTF-8
 */
function csv(iterable $rows, ?array $columns = null, string $separator = ',', bool $allowFormulas = false, bool $bom = true): string
{
    return Csv::write($rows, $columns, $separator, $allowFormulas, $bom);
}

// ------------------------------------------------------------- flow, layout

/** Puts things side by side. Each argument is a function that draws one column. */
function columns(callable ...$columns): void
{
    Runtime::page()->columns(...$columns);
}

/** Ends the run here. Everything drawn so far is shown. */
function stop(): never
{
    Runtime::page()->stop();
}

/**
 * Ends the run and loads the page again from the top. Call it after a button did
 * its work, so that tables above the button show the new data. Messages written
 * with success(), info(), warning() or error() after the click are kept.
 */
function rerun(): never
{
    Runtime::page()->rerun();
}

// ------------------------------------------------------------------ hosting

/** Who is using the tool: what the login check returned. Null when there is no login check. */
function user(): ?string
{
    return Runtime::page()->user;
}

/**
 * Settings for tools that are opened directly as scripts. Call it before anything is drawn,
 * typically in a file that every tool includes.
 *
 * @param callable|null $auth  the login check: receives the request, returns the user's name, or null to refuse
 * @param callable|null $log   called with an array for every button a user presses and every file a user downloads.
 *                             It should throw or return false when the entry could not be written: a download is then not sent
 * @param bool|null     $debug show error details in the page. By default they are shown only without a login check,
 *                             where nobody but this computer gets in. With a login check, pass true where you develop
 * @param string|null   $name  the name of the tool collection
 * @param string|null                 $language the language of the built-in texts ("Yes", "Reset", "No rows.") on every page:
 *                                              "en", "ar" or "de", or a language you pass the texts for.
 *                                              By default each page follows the language its title is written in
 * @param array<array-key,mixed>|null $texts    your own built-in texts, per language: ['de' => ['page.reset' => 'Leeren']].
 *                                              All texts and their ids are in resources/texts/en.php
 * @param string|null                 $uploads  the folder that keeps uploaded files until a tool is done with them.
 *                                              It must not be a folder the web server serves. By default a folder
 *                                              of its own inside PHP's temp folder
 */
function configure(
    ?callable $auth = null,
    ?callable $log = null,
    ?bool $debug = null,
    ?string $name = null,
    ?string $language = null,
    ?array $texts = null,
    ?string $uploads = null,
): void {
    Runtime::configure($auth, $log, $debug, $name, $language, $texts, $uploads);
}

/**
 * Deletes the uploaded files that are older than a day. The library does this
 * itself while files arrive. Call it from a scheduled job when the files of
 * users who left must not wait for the next upload to be cleaned away.
 *
 * The job has to run as the system user that runs PHP for the web server.
 * As another user it finds another folder, and nothing in it.
 *
 * @param string|null $folder the folder you passed as uploads:. null: the folder of this
 *                            system user inside PHP's temp folder
 *
 * @return int how many files were deleted
 */
function clean_uploads(?string $folder = null): int
{
    return (new UploadFolder($folder))->clean();
}

/**
 * Serves every PHP file in a folder as a tool, with a page that lists them.
 *
 * Without $auth the tools only answer to a browser on the computer they run on.
 * That check cannot see through every proxy or tunnel: anything that can be
 * reached from outside needs $auth.
 *
 * @param callable|null $auth  the login check: receives the request, returns the user's name, or null to refuse
 * @param callable|null $log   called with an array for every button a user presses and every file a user downloads.
 *                             It should throw or return false when the entry could not be written: a download is then not sent
 * @param bool|null     $debug show error details in the page. By default only without $auth; with it, pass true where you develop
 * @param string|null   $base  null: one address, tools are chosen with "?_tool=id".
 *                          A path such as "/admin/tools": tools get the addresses "/admin/tools/{id}"
 *                          (the web server must send those addresses to the script that calls serve()).
 * @param string|null                 $language the language of the built-in texts ("Yes", "Reset", "No rows.") on every page:
 *                                              "en", "ar" or "de", or a language you pass the texts for.
 *                                              By default each page follows the language its title is written in
 * @param array<array-key,mixed>|null $texts    your own built-in texts, per language: ['de' => ['page.reset' => 'Leeren']].
 *                                              All texts and their ids are in resources/texts/en.php
 * @param string|null                 $uploads  the folder that keeps uploaded files until a tool is done with them.
 *                                              It must not be a folder the web server serves. By default a folder
 *                                              of its own inside PHP's temp folder
 */
function serve(
    string $directory,
    ?callable $auth = null,
    ?callable $log = null,
    ?string $name = null,
    ?bool $debug = null,
    ?string $base = null,
    ?string $language = null,
    ?array $texts = null,
    ?string $uploads = null,
): void {
    Runtime::serve($directory, $auth, $log, $name, $debug, $base, $language, $texts, $uploads);
}
