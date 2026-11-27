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

// --------------------------------------------------------------------- data

/**
 * A table of rows. Click a column header to sort.
 *
 * @param iterable<mixed>             $rows       arrays or objects, for example what a database query returned
 * @param bool                        $selectable adds a tick box to every row
 * @param array<array-key,mixed>|null $columns    ['id', 'total'] to choose columns, ['id' => 'Order'] to also rename them
 * @param string                      $rowKey     the column that identifies a row. A row without it is known by what is in it:
 *                                                its tick is dropped when the row changes
 * @param int                         $limit      how many rows to show at most
 * @param string|null                 $empty      what to say when there are no rows. By default "No rows." in the language of the page
 *
 * @return list<mixed> the rows the user ticked, exactly as they were passed in (always empty unless $selectable)
 */
function table(
    iterable $rows,
    bool $selectable = false,
    ?array $columns = null,
    ?string $key = null,
    string $rowKey = 'id',
    int $limit = 200,
    ?string $empty = null,
): array {
    return Runtime::page()->table($rows, $selectable, $columns, $key, $rowKey, $limit, $empty);
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
 * @param callable|null $log   called with an array for every button a user presses
 * @param bool|null     $debug show error details in the page. By default they are shown only without a login check,
 *                             where nobody but this computer gets in. With a login check, pass true where you develop
 * @param string|null   $name  the name of the tool collection
 * @param string|null                 $language the language of the built-in texts ("Yes", "Reset", "No rows.") on every page:
 *                                              "en", "ar" or "de", or a language you pass the texts for.
 *                                              By default each page follows the language its title is written in
 * @param array<array-key,mixed>|null $texts    your own built-in texts, per language: ['de' => ['page.reset' => 'Leeren']].
 *                                              All texts and their ids are in resources/texts/en.php
 */
function configure(
    ?callable $auth = null,
    ?callable $log = null,
    ?bool $debug = null,
    ?string $name = null,
    ?string $language = null,
    ?array $texts = null,
): void {
    Runtime::configure($auth, $log, $debug, $name, $language, $texts);
}

/**
 * Serves every PHP file in a folder as a tool, with a page that lists them.
 *
 * Without $auth the tools only answer to a browser on the computer they run on.
 * That check cannot see through every proxy or tunnel: anything that can be
 * reached from outside needs $auth.
 *
 * @param callable|null $auth  the login check: receives the request, returns the user's name, or null to refuse
 * @param callable|null $log   called with an array for every button a user presses
 * @param bool|null     $debug show error details in the page. By default only without $auth; with it, pass true where you develop
 * @param string|null   $base  null: one address, tools are chosen with "?_tool=id".
 *                          A path such as "/admin/tools": tools get the addresses "/admin/tools/{id}"
 *                          (the web server must send those addresses to the script that calls serve()).
 * @param string|null                 $language the language of the built-in texts ("Yes", "Reset", "No rows.") on every page:
 *                                              "en", "ar" or "de", or a language you pass the texts for.
 *                                              By default each page follows the language its title is written in
 * @param array<array-key,mixed>|null $texts    your own built-in texts, per language: ['de' => ['page.reset' => 'Leeren']].
 *                                              All texts and their ids are in resources/texts/en.php
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
): void {
    Runtime::serve($directory, $auth, $log, $name, $debug, $base, $language, $texts);
}
