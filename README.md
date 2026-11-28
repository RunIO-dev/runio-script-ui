# Script UI

[![CI](https://github.com/RunIO-dev/runio-script-ui/actions/workflows/ci.yml/badge.svg)](https://github.com/RunIO-dev/runio-script-ui/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/runio/script-ui.svg)](https://packagist.org/packages/runio/script-ui)

Turn one PHP script into an internal tool with a web page. You write no HTML, no JavaScript, no controller and no template.

> This is a first version, built to try the idea. Until version 1.0 a new version may change how a function is called. The [changelog](CHANGELOG.md) says what changed.

```php
<?php // tools/refund.php
require __DIR__ . '/../bootstrap.php';   // your app: $db, $refunds

use function Runio\ScriptUi\{title, text, select, table, button, success, rerun};

title('Refund orders');
$email  = text('Customer email');
$status = select('Status', ['paid', 'shipped']);

if ($email !== '') {
    $orders = $db->fetchAll('SELECT id, total, created_at FROM orders WHERE email = ? AND status = ?', [$email, $status]);
    $picked = table($orders, selectable: true);

    if ($picked && button('Refund selected', style: 'danger', confirm: 'Refund ' . count($picked) . ' orders?')) {
        foreach ($picked as $order) {
            $refunds->create($order['id']);
        }
        success('Refunded ' . count($picked) . ' orders.');
        rerun();
    }
}
```

That script is the whole tool: a search field, a filter, a sortable table with tick boxes, a button that asks before it acts, and a message when it is done.

## Installation

```bash
composer require runio/script-ui
```

It needs PHP 8.2 or newer with the `mbstring` and `session` extensions, and no other package.

## Your first tool

Make a folder for your tools and put one file into it:

```php
<?php // tools/hello.php
// Hello
// My first tool.

use function Runio\ScriptUi\{title, text, button, success};

title('Hello');
$name = text('Your name');

if (button('Greet') && $name !== '') {
    success('Hello, ' . $name . '.');
}
```

Serve the folder, and open <http://localhost:8765>:

```bash
php vendor/bin/script-ui serve tools
```

The first comment of a file is the name of the tool in the list, and the comment lines after it describe it. This server is the one that comes with PHP, and it answers to your own computer only. For a real server see [Use it in your application](#use-it-in-your-application).

## Try the demo

The demo is not part of the Composer package. It runs from a copy of this repository:

```bash
git clone https://github.com/RunIO-dev/runio-script-ui.git
cd runio-script-ui
composer install
php bin/script-ui serve examples/tools
```

Open <http://localhost:8765>. The demo has eight tools: refunds, orders to ship page by page, an import of orders from a CSV file, a form for coupons that says under each field what is wrong with it, a sales dashboard with charts, a customer list with filters, an Arabic page (right to left, with the library's own texts in Arabic), and the audit log. The demo data lives in `examples/var/` and can be deleted at any time.

## How it works

A tool is a script that runs from top to bottom. Every time the user changes an input or presses a button, the page is submitted and **the script runs again**. Each input function returns the current value, so the script reads like a description of the page.

- `text()`, `select()`, `number()` and the other inputs return what the user entered.
- `button()` returns `true` only in the run that its click started. `if (button('Save')) { ... }` does the work once per click.
- `button('Delete', confirm: 'Delete 3 rows?')` returns `true` only after the user answered yes to exactly that question.
- `table($rows, selectable: true)` returns the rows the user ticked.
- `file_input('Orders', types: ['csv'])` returns the file the user uploaded, on every run until it is removed.
- `download('Download CSV', $rows, 'orders.csv')` hands the user a file.
- `rerun()` loads the page again after an action, so a table above the button shows the new data. Messages written after the click are kept.

Inputs are saved per tool, so a reload keeps them. The "Reset" link in the header forgets them.

The page works without JavaScript as a plain form. With JavaScript it updates in place: changing an input reruns the script in the background and only the content is swapped.

## Functions

| Group | Functions |
|---|---|
| Text | `title` `heading` `note` `code` `json` `link` `divider` |
| Messages | `success` `info` `warning` `error` |
| Inputs | `text` `textarea` `number` `select` `radio` `multiselect` `checkbox` `date_input` `file_input` |
| Checks | `field_error` `valid` |
| Data | `table` `chart` `metric` |
| Actions | `button` `download` `csv` |
| Layout and flow | `columns` `expander` `tabs` `refresh` `stop` `rerun` |
| Hosting | `serve` `configure` `user` `clean_uploads` |

All of them are documented in [`src/functions.php`](src/functions.php). Import the ones you use, or give the namespace a short name:

```php
use Runio\ScriptUi as ui;

ui\title('Find orders');
```

### Keys

Every input and button has a key. It names the form field and the saved value. By default it is made from the label (`'Customer email'` becomes `customer-email`).

Two inputs or buttons with the same label on one page are an error until each has its own key: `text('Name', key: 'billing-name')`. This is on purpose. Numbered keys would follow the order on the page, and a click could land on another row when the list changes. In a loop, build the key from the row:

```php
foreach ($orders as $order) {
    if (button('Refund', key: 'refund-' . $order['id'])) {
        $refunds->create($order['id']);
    }
}
```

### Choices

Three functions offer a list of options, and all three take it the same way.

```php
$status = select('Status', ['paid', 'shipped', 'refunded']);                           // a list that opens
$days = radio('Period', [7 => 'Last 7 days', 30 => 'Last 30 days'], horizontal: true); // every option in view
$countries = multiselect('Country', ['DE', 'FR', 'JO'], default: ['DE']);              // a tick box for each
```

`select()` and `radio()` return one option: the first one until the user chooses another, or the one you name with `default:`. Use `radio()` for a handful of options that are worth seeing at a glance, and `select()` when the list is long. `multiselect()` returns the ticked options as a list, in the order of the options, and an empty list when none is ticked.

`['paid', 'shipped']` returns the chosen text. `[7 => 'Last 7 days', 30 => 'Last 30 days']` shows the texts and returns 7 or 30. PHP cannot tell `[0 => 'No', 1 => 'Yes']` from the list `['No', 'Yes']`, so when your keys count up from 0, add `byKey: true`.

What comes back is always one of the options you passed, whatever the browser sent. An option that is in the list twice is offered once, so the values of a column are fine as they are: `multiselect('Country', array_column($customers, 'country'))`.

The options of `radio()` and `multiselect()` stand one under the other, in several columns when there are more than eight, or side by side with `horizontal: true`. Both are groups with the label as their name, so a screen reader announces them as such, and the arrow keys walk through a radio group.

A common filter is "none ticked means all":

```php
$countries = multiselect('Country', $all, help: 'Tick none to see every country.');
$found = array_filter($customers, fn ($c) => $countries === [] || in_array($c['country'], $countries, true));
```

### What is wrong with an input

`field_error()` says under an input what is wrong with what it holds. `valid()` tells whether anything was found wrong in this run.

```php
$amount = number('Amount');
$email = text('Customer email');

if (button('Refund')) {
    if ($amount === null || $amount <= 0) {
        field_error('Amount', 'Enter an amount above 0.');
    }
    if (!str_contains($email, '@')) {
        field_error('Customer email', 'Enter an email address.');
    }

    if (valid()) {
        $refunds->create($email, $amount);
        success('Refunded.');
    }
}
```

The input is named by its label, exactly as it was written, or by its key. It has to be on the page already, so the call stands below the input. Every kind of input can be marked. A button or a table cannot: for those there is `error()`.

**The user gets to see it.** The note is red with a sign in front, and the frame of the input turns red. After a pressed button the first input that is wrong gets the keyboard focus, an expander that hides it opens, and a group of tabs shows the tab it is in. Software that reads the page out says that the input is invalid and reads the note with it. Of the answers to a press, the note of the input with the focus is read out by itself, and so is the note about a file that was turned down.

**A message lasts for one run.** Nothing is kept of it, so there are two ways to check, and they can be mixed:

- *Behind the button*, as above. The notes are the answer to the press. When the user corrects one field and goes on to the next, the page runs again without a press, and all notes are gone until the button is pressed again.
- *On every run*, outside `if (button(...))`. The note comes as soon as the user leaves the field, and it stays until the field is right. Leave an empty field alone there, or a new page starts full of red: `if ($email !== '' && !str_contains($email, '@')) { field_error(...); }`. What is missing is asked for behind the button.

`field_error()` does not stop the script: ask `valid()` before the work is done. A file that `file_input()` turned down in that run counts as well. Its field is marked in the same way, and `valid()` is false, so no work is done without a file the user believes to be attached. A pressed button goes to the log either way. The entry says what was found wrong, so nobody takes a press that was turned down for work that was done. The example [`examples/tools/coupon.php`](examples/tools/coupon.php) uses both ways.

### Tables

Pass all rows to `table($rows)`. A click on a header sorts all of them. A long table is shown one page at a time: 25 rows, or what you set with `pageSize:` (up to 200).

`table($rows, search: true)` puts a search box over the table. It filters while the user types and looks for every word, in any order, in the columns that are shown. A column you left out with `columns:` is never searched. Upper and lower case are the same, and so are the ways one Arabic word gets typed: `احمد` finds `أَحْمَد`. With PHP's intl extension accents are ignored too, so `jose` finds `José`.

The table starts again at its first page when it is sorted or searched, and when the user changes another input and the rows are different after that, as with a filter. Rows that change on their own, such as a new order, leave the page where it is.

### Rows you can tick

`table($rows, selectable: true)` returns the ticked rows. A row is found again by its `id` column (name another one with `rowKey:`), so give every row an id of its own. A row without an id, or with an id that another row has too, is recognised by what is in it, and it loses its tick when it changes. A tick never moves to a different row. In the log a ticked row is called by its id, or by its place in the rows (`#3`) when it has none.

A tick stays when the user turns the page or sorts, so the rows you get back can come from any page. The line under the table says how many rows are ticked and how many of them are on other pages. The box in the header ticks the rows of the page, or unticks them when all are ticked. It acts on the rows the user saw: a row that arrived in the meantime is left alone. "Clear selection" unticks every page. One table takes up to 1,000 ticks.

The search only hides rows. A ticked row that it hides is not returned, so a button never acts on a row that is not in the list on screen. The line under the table says how many ticked rows are hidden, and each is ticked again as soon as the search shows it. Ticks also wait while a filter of your tool leaves the table without rows.

### Uploaded files

`file_input()` draws a field for a file and returns the file, or `null` while there is none:

```php
$file = file_input('Orders as CSV', types: ['csv'], maxSize: 5 * 1024 * 1024);

if ($file !== null) {
    $orders = $file->rows();                     // one row per line: ['email' => ..., 'total' => ...]
    table($orders);

    if (button('Import ' . count($orders) . ' orders', confirm: 'Add them to the shop?')) {
        $importer->save($orders);
        $file->forget();                         // done: delete the file
        success('Imported ' . $file->name . '.');
        rerun();
    }
}
```

The file is an `Upload` with `name`, `size`, `path`, `contents()`, `rows()`, `saveTo()` and `forget()`. With `multiple: true` the field takes up to 20 files and returns a list, which is empty at first. A new file takes the place of the old one in a field for one file, and is added in a field for several.

**`rows()` reads a CSV file, whatever spreadsheet wrote it.** The first line names the columns, and every other line becomes one row: `['email' => 'ava@example.com', 'total' => '24.50']`. It takes cells apart by `,`, `;` or a tab, with or without the mark for UTF-8, in UTF-8, in UTF-16, or in the Western Windows encoding that older versions of Excel write. Empty lines are left out, and a line that ends early gets empty cells. For another encoding, name it: `rows(encoding: 'Windows-1256')` reads Arabic from an older Excel (PHP needs its iconv extension for that one).

Every cell is text, exactly as it stands in the file: `007` stays `007`, and `1,50` is not a number until your code says so. The file comes from a user, so look whether the columns you need are there. A file that is not text has no rows.

**The file is still there on the next run.** A tool runs again on every interaction, so the library keeps the file on the server, and the page shows its name and size with a "Remove" link. The user can look at a preview, change other inputs and confirm, all with the same file.

**Call `forget()` when you are done.** It deletes the file at once. Otherwise a file waits until the user removes or replaces it, the tool is reset, the field is no longer drawn, or a day has passed.

**A field that is not drawn gives its file up**, as a text field loses its text. That includes a run that ends above the field, with `stop()` or an early `return`. When a file should survive that, draw its field before anything that can end the run.

To keep a file, copy it with `saveTo($path)` to a place of your own. `saveTo()` refuses to overwrite a file unless you pass `overwrite: true`. The copy can be read by the PHP user only: `chmod()` it when somebody else has to read it, such as a web server that runs as another user.

The files wait in a folder of their own inside PHP's temp folder. On Linux and macOS it is open to the PHP user only. Windows has no permissions of that kind, so name a folder there. You do that with `uploads:` in `serve()` or `configure()`. Give the library a folder of its own, outside the folders your web server serves, that only your application writes to. On Linux and macOS the library refuses a folder that everybody may write to. The rest is yours to set up.

Files of users who never came back are deleted when they are a day old, while other files arrive. On a quiet server call `Runio\ScriptUi\clean_uploads()` from a daily job, as the system user that runs PHP for your web server. A job that runs as another user does not see the default folder, and neither does one on a server that gives every service a temp folder of its own. In both cases set `uploads:` and pass the same folder to `clean_uploads($folder)`.

How large and how many:

- `maxSize:` is in bytes. PHP has limits of its own and the smaller one counts: `upload_max_filesize` for a file (2 MB unless you changed it), `post_max_size` for a request with everything in it, `max_file_uploads` for the files of one request. The page writes the limit under the field.
- With JavaScript, a file that is too large is turned down before it is sent. Without it the server says so afterwards.
- A web server or proxy in front of PHP can take less than PHP does, and the page cannot know its limit. nginx takes 1 MB unless `client_max_body_size` says otherwise. Such an upload is sent and then turned down with a message.

With JavaScript a file is sent as soon as it is chosen or dropped onto the field, and the page is locked until it has arrived. Without it the file travels with the next button.

**What comes from the user's computer is not to be trusted.** `types: ['csv']` means that the name ends in `.csv`. It does not mean that the file holds CSV. The library makes the name safe to show and to log, and it never looks into the file, runs it or serves it. So: read the file from `$file->path`, never build a path from `$file->name`, never save an upload under a name of the user's choosing in a folder your web server serves, and check what is in the file before you use it.

In the log, a button press names each file it got by name, size and the SHA-256 of its content.

### Downloads

`download('Download CSV', $orders, 'orders.csv')` draws a button that hands the user a file. Rows become CSV, or JSON when the name ends in `.json`. Text is sent as it is, so any other format works when you make the content yourself.

Pass a function when making the content takes time. It is only called when the user asks for the file:

```php
download('Export all orders', fn () => $db->fetchAll('SELECT * FROM orders'), 'orders.csv');
```

A CSV file is written for a spreadsheet and for a program at once. It starts with the mark that tells Excel it is UTF-8, numbers are exact, dates are written like `2026-03-01 14:30:05`, and the first line names the columns as your rows do (`created_at`, not "Created at"). For other choices there is `csv()`:

```php
download('Export', fn () => csv($orders, columns: ['id' => 'Order', 'total'], separator: ';'), 'orders.csv');
```

In the CSV that the library writes, a cell that a spreadsheet would run as a formula (it starts with `=`, `+`, `-` or `@`) gets a `'` in front. The names of the columns are treated the same way. Numbers are left alone: `-12.50` stays as it is, while a phone number such as `+49 170 1234567` becomes `'+49 170 1234567` and a lone `-` becomes `'-`. Pass `allowFormulas: true` to `csv()` only when every cell comes from you. Text that you pass to `download()` yourself is sent as it is.

The file is made in memory. For a very long export pass the rows as a function that reads them one by one, and name the columns with `csv($rows, columns: [...])`: the rows are then not kept while the file is written.

The run ends where the file is made, as after `stop()`. With JavaScript the page stays in place and shows that it is busy, and a second click does not send a second file. Without it the browser saves the file from the plain form.

### Expanders and tabs

Two ways to keep a long page short.

```php
expander('Advanced options', function () use (&$limit): void {
    $limit = number('Limit', default: 100);
});

tabs([
    'Orders' => fn () => table($orders),
    'Customers' => fn () => table($customers),
]);
```

`expander()` puts a part of the page under a line that opens and closes it. `tabs()` puts several parts behind headers and shows one of them at a time.

**What is out of sight still runs.** The content of a closed expander and of every tab runs on every run, like the rest of the script. The inputs inside keep their values and give them to the script, an uploaded file stays where it is, and the log names all of them. So opening an expander or changing the tab asks nothing of the server, and neither is an action. Work that takes long belongs behind a button, not into a tab.

**Out of sight is not private.** A closed expander and a tab that is not open are in the page all the same, and everybody who can open the tool can read them. What a user must not see must not be drawn: check before you draw it. `stop()` inside a part ends that part only, so it is no permission check there. A check that is meant for the whole page belongs above the first part.

- The page remembers what the user left open, also over a reload. A part that a run does not draw is forgotten, like an input, and starts the way the script says when it comes back.
- `stop()` inside ends that part and not the page, like `return`. `rerun()` and `download()` end the run as they do everywhere.
- The content is a function: `fn () => ...`, a method, or `name(...)`. The name of a function in quotes is not run, for `columns()` too.
- A part that fails is drawn open. So is an expander when a button inside it was pressed: its answer must not stay out of sight.
- The label of a tab may change between runs, as in `'Orders (' . count($orders) . ')'`. The open tab is found again by its label, and by its place when the label changed.
- An expander has a key like an input, made from its label. Tabs have no label of their own and are numbered like tables (`tabs`, `tabs-2`) unless you pass `key:`.
- With the keyboard, the arrow keys go from header to header, and Home and End to the first and the last. Where the headers do not fit in one line, the line moves sideways and fades out at the end where it goes on.
- Both work without JavaScript. The expander is the browser's own `<details>`, and a header is a button that sends the form.

### Pages that stay up to date

A dashboard or the state of a queue should show what is true now, not what was true when the page was opened.

```php
title('Queue');
refresh(30);

metric('Waiting', $queue->waiting());
table($queue->jobs());
```

`refresh(30)` runs the script again 30 seconds after every answer, as long as somebody has the page open and in view. Where it is called, a small line says when the page was made, with a button that pauses it.

**It never takes the page from under your hands.** A refresh waits while a text is half typed, while a question of a button waits for its answer, and for two seconds after the mouse or a key was last touched. It also waits while a list or a calendar is open or text is marked, for a minute at most. An answer that arrives in such a moment waits too. What a button said stays until you are back and had ten seconds to read it.

**It is a run that nobody asked for.** A refresh presses no button, sends no file, writes nothing to the log and does not change the inputs that the page remembers. What a button showed is gone after the next refresh, as it is after any other run. So `refresh()` suits pages that show the same thing on every run.

**It is a whole run all the same.** Whatever the script does on every run, it now does every 30 seconds with nobody at the page: counting a visit, marking a message as read, taking a job from a queue. Work of that kind belongs behind a button, as it always did.

- Every refresh is a whole run of the script, so choose a rate that fits the work. The lowest is 2 seconds. The wait counts from the answer, so runs never pile up.
- Call it near the top. A page that ends before it reaches the call, with `stop()` for example, stops refreshing. A run that fails does not end it, and neither does a server that is away for a while: the page tries again, and waits twice as long after every failure, up to five minutes.
- Its line says "Paused" when the page stands still and holds the button that starts it again. So it has to be in sight: `refresh()` inside `expander()` or `tabs()` is an error.
- A page that is out of sight, because another browser tab is in front, does not run. It catches up when it comes back into view.
- A page that keeps asking keeps its session alive. So it stops by itself when nobody touched it for an hour, and says "Paused". `stopAfter:` sets another time in seconds, longer than the wait. `stopAfter: null` lets a screen on a wall run for ever.
- The page script does that stopping, not the server. Your login check runs for a refresh as for any other request. If it logs people out after a time without a request, it should not count the requests that nobody asked for. They carry the header `X-Script-Ui: refresh`, which PHP shows as `$_SERVER['HTTP_X_SCRIPT_UI']`.
- Without JavaScript nothing refreshes. The line then only says when the page was made.

### Links that fill in fields

`/refund?customer-email=ava@example.com` opens the tool with that field filled in. For `multiselect()` a link names every ticked option, `/customers?country[]=DE&country[]=FR`, and one option needs no brackets. A link can only fill plain fields. It can never press a button or tick a row of a table. What a link filled in is not saved until the user changes or submits something.

## Use it in your application

**A folder of tools.** Put this in a script behind your web server:

```php
// public/admin.php: the only file of all this that lies in the web root
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../bootstrap.php';         // starts your session, connects your database

Runio\ScriptUi\serve(
    __DIR__ . '/../tools',
    auth: fn () => $_SESSION['admin_email'] ?? null,   // who is asking, or null to refuse
    log: fn (array $entry) => $auditLog->write($entry), // called for every button a user presses and every file a user downloads
    name: 'Back office',
);
```

An entry is an array with `time`, `user`, `tool`, `action` (the label of the button), `key`, `confirmed`, `inputs`, `error`, `field_errors` and `download`. `field_errors` holds what the page said is wrong in that run, by the key of the input: the messages of `field_error()`, and those of a file field that turned a file down. When it is not empty, the button most likely did not do its work. `inputs` holds what every input of the page returned in that run, by key. Not every value is a text: a tick box gives `true` or `false`, `multiselect()` a list of options, a table the ids of its ticked rows, and a file field the name, the size and the SHA-256 of the file. Turn the entry into JSON if your log takes one line of text.

Every PHP file in the folder becomes a tool, listed on an index page. The first comment in a file is its title, more comment lines are its description. Files that start with `_` are helpers and are never served.

**Keep the folder of tools out of the web root**, next to `vendor/`. Only the script above belongs where the web server looks for files. A file that the web server runs by itself never passes `serve()`, so nobody asks `auth:` or `allow:` about it. That goes for the helper files in the folder too.

When the folder has to lie in the web root, close it in the web server. With Apache 2.4 that is a file `.htaccess` in the folder with the line `Require all denied` (it needs `AllowOverride`). With nginx it is `location ^~ /tools/ { deny all; }`. Then try it: `/tools/refund.php` has to answer 403 or 404.

The list of tools says so when the folder lies in the web root. It says it only where error details are shown: on your own computer without a login check, or with `debug: true`. The library cannot see what the web server was told, so the note stays while the folder lies there. No note is not a proof that the folder is closed. Where the web server runs on another machine or in another container, PHP may not see the web root at all, and a link from the web root to the folder is not seen either.

With a login check, error details go to the error log and not into the page. Pass `debug: true` where you develop.

**Who may open which tool.** The login check lets somebody in, and then every tool is open to them. `allow:` decides for one user and one tool:

```php
Runio\ScriptUi\serve(
    __DIR__ . '/../tools',
    auth: fn () => $_SESSION['admin_email'] ?? null,
    allow: fn (mixed $user, string $tool) => $permissions->has($user, 'tool.' . $tool),
);
```

It gets two things. The first is what your login check returned, as it is: a name, a number or the user object of your application. It is `null` when the login check only said `true`, and when there is no login check. The second is the id of the tool: the file name without `.php`, so `refund` for `tools/refund.php`. It answers `true` or `false`. Compare strictly where you compare: `in_array($user, $team, true)`.

A tool that is not allowed is left out of the list, and whoever opens its address reads "Not allowed" before anything of the tool runs, whether that is a page view, a pressed button or a download. The question is asked on every request, so access that you take away ends with the next click, also on a page that is open.

Only `true` opens a tool. Any answer other than `true` or `false`, and a check that throws, lets nobody in, and the reason goes to the error log. That holds for the list too: it asks once for every tool, so one answer that is neither closes the list for everybody. Keep the check cheap for the same reason. What somebody may do inside a tool they may open stays the tool's own `if`: `user()` tells it who is asking.

A file name is an address, and "Not allowed" is another answer than "There is no tool at this address". So whoever is logged in can find out that a tool with a certain name is there, without seeing anything of it. Name the files with that in mind.

**Variables for the tools.** A tool is a script of its own, so it sees no variable of yours. `variables:` hands some over by name:

```php
Runio\ScriptUi\serve(
    __DIR__ . '/../tools',
    variables: ['orders' => $orderRepository, 'mailer' => $mailer],
);
```

Every tool of the folder then starts with `$orders` and `$mailer`. An object is the object itself. Everything else is a copy that a tool may change for itself. A line such as `/** @var OrderRepository $orders */` at the top of a tool tells your editor about the variable.

By default tools are chosen with `?_tool=refund`. With `base: '/admin/tools'` they get clean addresses such as `/admin/tools/refund` (send those addresses to the script in your web server configuration). The list is at the base as you write it: at `/admin/tools`, or at `/admin/tools/` when the base ends with a slash.

**One script on its own.** A script that the web server runs directly works too. Call `Runio\ScriptUi\configure(auth: ...)` in a file every tool includes, before anything is drawn. See [`examples/direct/hello.php`](examples/direct/hello.php). `configure()` takes `allow:` too. A script on its own is asked about by its file name: `payroll` for `payroll.php`. Then the name has to be one that a tool of a folder could have (lower-case letters, digits, `-` and `_`), or the script stays closed. Both checks are asked when the script calls its first function of the library. Put `title()` at the top: what stands above it runs for everybody. A script that shows several pages, such as an `index.php` with `?page=`, is one script, and it is asked about as `index`.

### Inside Symfony and Laravel

`serve()` sends the page itself. A framework wants to send it: a controller returns a response, and the framework adds its cookies and headers on the way out. `respond()` takes everything `serve()` takes. It works out the same page and hands it back, with `->status`, `->headers` and `->body`. One route leads every address under `base:` to it. The folder of tools lies next to `src/` or `app/`, outside the web root.

Symfony:

```php
// src/Controller/ToolsController.php
use function Runio\ScriptUi\respond;

final class ToolsController extends AbstractController
{
    #[Route('/admin/tools/{tool}', defaults: ['tool' => ''], methods: ['GET', 'POST'])]
    public function tools(Request $request): Response
    {
        $page = respond(
            $this->getParameter('kernel.project_dir') . '/tools',
            base: '/admin/tools',
            auth: fn () => $this->getUser(),
            allow: fn ($user, string $tool) => $tool !== 'payroll' || $this->isGranted('ROLE_PAYROLL'),
            session: $request->getSession(),
        );

        return new Response($page->body, $page->status, $page->headers);
    }
}
```

Laravel:

```php
// routes/web.php
use function Runio\ScriptUi\respond;

Route::match(['get', 'post'], '/admin/tools/{tool?}', function (Request $request) {
    $page = respond(
        base_path('tools'),
        base: '/admin/tools',
        auth: fn () => $request->user(),
        allow: fn ($user, string $tool) => Gate::forUser($user)->allows('open-tool', $tool),
        session: $request->session(),
    );

    return response($page->body, $page->status, $page->headers);
})->block();
```

```php
// bootstrap/app.php, inside withMiddleware()
$middleware->validateCsrfTokens(except: ['admin/tools', 'admin/tools/*']);
```

Pass all of `$page->headers` on. They carry the rule that lets the script of the page run.

**The session.** With `session:` the tools keep what they keep in the session of your application, under the one key `_script_ui`, and a logout ends it. Without it they use PHP's own session. In Laravel that is a second session next to Laravel's, and a logout of Laravel does not end it.

**The user.** `auth:` returns the user object of the framework, and `allow:` gets that object. `user()` and the log name it by `getUserIdentifier()` in Symfony and by `getAuthIdentifier()` in Laravel, which is the key of the row unless your model says otherwise. To show an email instead, return it: `auth: fn () => $request->user()?->email`. Then `allow:` gets the email too.

**Laravel checks every form for its own token.** The forms of the tools carry the token of the library, and the library checks it on every request. So the route is taken out of Laravel's check, with the line for `bootstrap/app.php` above. Without it Laravel 12 answers every pressed button with "419 Page Expired". Laravel 13 lets a button through when the browser says that the request comes from the same site. Browsers say that on HTTPS and on localhost only, so the line is needed there as well.

**Laravel does not lock its session.** Two requests of one user at the same moment each write the whole session, and the later one wins. What a tool kept in the other is lost then, for instance when a page that refreshes itself and a click meet. `->block()` lets the second wait for the first. It needs a cache that can lock, such as file, database or Redis. Symfony keeps its session in PHP's own by default, which locks.

**Errors.** The error handling of the framework stays in charge. Laravel makes an error of every PHP warning, and Symfony does in its dev environment: a warning in a tool then ends the run, and the page says that the tool failed. After a fatal error the framework shows its own error page.

**End a run with `stop()`, not with `exit`.** `exit` ends the framework together with the tool. The page is still sent and what the tool keeps is saved, but nothing of the framework runs after that line. In Laravel the lock of `->block()` is not given back, and the next click of that user waits until it runs out, which is 10 seconds.

**Another Content-Security-Policy.** The page brings its own. A browser applies every policy it gets, so a second one that your application adds to all its responses blocks the script and the styles of the page. Leave this route out of it.

**What does not work.** `respond()` takes the request from PHP itself (`$_SERVER`, `$_GET`, `$_POST` and `$_FILES`). The test client of a framework does not fill those in, so a tool cannot be opened with `$client->request()` or `$this->get()`. `respond()` says so with an exception. Try a tool in a browser test. Servers that keep PHP running between requests, such as Laravel Octane, RoadRunner, Swoole and FrankenPHP in worker mode, are not supported.

**What was tried.** Laravel 12 and 13 and Symfony 7.4 and 8.1, as they are after a fresh install, on PHP's built-in web server. The suite in [`e2e/frameworks`](e2e/frameworks) runs the same tools inside each of them. Not tried with a framework: php-fpm, Apache and nginx, Symfony's prod environment, and a user that comes from a database.

### Languages

The texts the library writes itself ("Yes", "Cancel", "Reset", "No rows.") come in English, Arabic and German. Each page follows the language its title is written in, so a tool with an Arabic title is Arabic throughout and runs right to left. Call `title()` first: what is drawn before it does not know the language yet.

To use one language on every page, set it:

```php
Runio\ScriptUi\serve(__DIR__ . '/../tools', language: 'de');
```

Your own words for built-in texts, or a language that is not built in, go into `texts:`. All texts and their ids are in [`resources/texts/en.php`](resources/texts/en.php). What you leave out stays English.

```php
Runio\ScriptUi\serve(
    __DIR__ . '/../tools',
    language: 'fr',
    texts: ['fr' => ['confirm.yes' => 'Oui', 'confirm.no' => 'Annuler', 'page.reset' => 'Réinitialiser']],
);
```

`configure()` takes the same two settings. `button(yes:, no:)` and `table(empty:)` still set a text for one place. A Hebrew title turns the page right to left as well; its built-in texts stay English until you pass Hebrew ones.

Pages that are not a tool have no title to follow: the list of tools and pages such as "Not allowed" use `language:`, or else the language `name:` is written in.

## What the library does for safety

- **No login check, no outside access.** Without `auth`, tools only answer to a browser on the computer they run on. The request must come from a loopback address, with a local host name, and must not look passed on (proxy headers, HTTP/1.0, `Connection: close`). No check of this kind can see through every proxy or tunnel, so anything that can be reached from outside needs `auth`.
- **A tool can be closed to some users.** `allow:` is asked before anything of a tool runs, on every request, and only a plain `true` opens the tool. Without `allow:` every tool is open to everybody who gets past the login check. Both checks are settings of `serve()`: a tool file that the web server runs by itself knows nothing of them, which is why the folder of tools stays out of the web root.
- **What a tool keeps belongs to one user.** The inputs, the uploaded files and the messages that the library keeps in a session are dropped when the login check names another user in that session, as after a login without a logout before it. The forms of the user before are not taken any more. A login check that names nobody cannot tell users apart.
- **Forged requests are refused.** Every form carries a token. A button only counts on a form that was sent from the page itself. The "Reset" link carries a code of its own.
- **Everything is escaped.** Labels, table cells and messages are text, never HTML. Output printed with `echo` is shown as text too.
- **A strict Content-Security-Policy** on every page: no inline styles, no inline handlers, nothing from other sites, no framing.
- **A download is a file, never a page.** It is sent with the order to save it, never as HTML or a script, and it may do nothing if it is opened in the browser after all. Its name is made safe, also when it contains something a user typed. In CSV that the library writes, formula cells are defused. Every download goes to the log with the name and the size of the file. When the log cannot be written (the `log` callback throws or returns false), the file is not sent.
- **An uploaded file is out of reach.** It lies under a random name that says nothing about it, in a folder that only the PHP user reads. (That holds for the library's own folder on Linux and macOS. A folder you name with `uploads:` is as closed as you made it.) The page names it by a random handle that is not that name and only counts for the session, the tool and the field the file was uploaded to. No request can name a path, a file of another user or a file of another tool. The name the user gave is cleaned before it is shown or logged, and it is never used on the disk. The library does not serve uploads and does not run them.
- **A page that refreshes changes nothing.** A run that the page starts by itself presses no button, takes no file and saves nothing, whatever its request says. It needs the token of the form like every other run.
- **Selections come from your data.** `table()` returns rows from the array you passed in. The browser only says which rows are ticked, and a tick never moves to another row or another table. A row that the search of a table hides is not returned, and the search reads only the columns that are shown.
- **A column you leave out stays out.** What a table puts into the page about a row, besides the cells it shows, is made with a secret that never leaves the session. Neither the id of a row nor a column you left out with `columns:` can be read from the page, or guessed by trying values against it.
- **Files are found by listing the folder.** An address can never point outside it.
- **Error details stay private.** They are shown only while no login check is set, because then nobody but your own computer gets in. With a login check they go to the error log unless you pass `debug: true`. PHP warnings and notices are kept out of the page in the same way.

What stays your job: decide who may open which tool (`allow:`) and who may do what inside one (`user()` tells you who is asking), and never change data outside an `if (button(...))` block, because the script also runs on every plain page view.

## Limits of this first version

- Uploads have no progress bar, and cannot be resumed or stopped once they are on their way. A field holds up to 20 files and a session 200. The kind of a file is judged by the ending of its name only.
- Nothing limits the bytes that uploads take on the disk, only their number: a session keeps up to 200 files of the size you allow, for a day at most. Somebody who is logged in and means harm can use that. Keep `maxSize:` and PHP's limits as low as your tools need.
- Uploaded files wait in PHP's temp folder unless you set `uploads:`. They are closed to other system users, but another PHP application that runs as the same user can read them.
- While a tool works on a request, PHP holds the session, and other requests of the same user wait. That shows with a large upload that a tool reads for a long time.
- A table gets all its rows from the script and searches, sorts and pages them itself, on every run. That is quick up to about twenty thousand rows: on a laptop a search took 0.1 seconds, and so did sorting by a text column. For more, filter in the database first.
- `multiselect()` is a group of tick boxes. It has no search and no "select all", so it suits lists of up to a few dozen options. The ticks of one field are remembered between page views up to 8 KB of option values. Beyond that the next page view starts from the default again.
- A message of `field_error()` is gone with the next run, unless the script says it again. Nothing keeps a note under its field until that field is corrected: check on every run for that. Only inputs can be marked, not a table and not a button.
- Every tab of `tabs()` runs on every run. There is no way yet to run only the open one, and a link cannot open a tab or an expander.
- `refresh()` runs the whole script and swaps the whole content. There is no way yet to refresh one part of a page, and the server pushes nothing: the page asks. When something above the place you look at grows or shrinks, the page moves under your eyes.
- Charts are bar and line charts with one axis and at most 8 series.
- State lives in the session: PHP's own, or the one you pass to `respond()` with `session:`. Two browser tabs of the same tool share saved inputs. A session remembers the inputs of the 50 tools that were used last.
- A script that runs on its own (without `serve()`) is told apart by its file and its path. Tools that share both and differ only in the query string (`index.php?page=refund`) work, but they do not remember their inputs separately.

## Development and tests

This repository holds the library at its root, plus example tools in `examples/` and browser tests in `e2e/`. Neither of those ships with the Composer package.

Unit tests:

```bash
composer install
php vendor/bin/phpunit
```

The suite runs every function through the same kernel a real request uses. It needs no web server.

Browser tests, written with [Playwright](https://playwright.dev). They need PHP 8.2 or newer and Node.js 20 or newer:

```bash
composer install    # once, for the autoloader
cd e2e
npm install
npx playwright install chromium
npx playwright test
```

They drive the example tools in a real browser: the refund flow from the first key press to the audit log, downloads, uploads up to a file that is dragged onto the page, the charts, the Arabic page, a dark theme, a phone-sized window and the page without JavaScript. They start their own servers on ports 8775 to 8780 and work on a copy of the example tools, so a demo that is running keeps its data.

The same kind of tools run inside a real Laravel and a real Symfony in a suite of its own. It downloads the two applications with Composer first, into a folder outside this repository, which takes a minute or two:

```bash
cd e2e
node frameworks/setup.mjs
npx playwright test -c frameworks
```

`LARAVEL='^12.0' SYMFONY='7.4.*' node frameworks/setup.mjs` chooses other versions. The two servers use the ports 8781 and 8782.

## License

MIT. See [LICENSE](LICENSE).
