# Script UI

Turn one PHP script into an internal tool with a web page. You write no HTML, no JavaScript, no controller and no template.

> This is a first version, built to try the idea.

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

## Try the demo

From a copy of this repository:

```bash
composer install
php bin/script-ui serve examples/tools
```

Open <http://localhost:8765>. The demo has seven tools: refunds, orders to ship page by page, an import of orders from a CSV file, a sales dashboard with charts, a customer list with filters, an Arabic page (right to left, with the library's own texts in Arabic), and the audit log. The demo data lives in `examples/var/` and can be deleted at any time.

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
| Inputs | `text` `textarea` `number` `select` `checkbox` `date_input` `file_input` |
| Data | `table` `chart` `metric` |
| Actions | `button` `download` `csv` |
| Layout and flow | `columns` `stop` `rerun` |
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

`select('Status', ['paid', 'shipped'])` returns the chosen text. `select('Period', [7 => 'Last 7 days', 30 => 'Last 30 days'])` shows the texts and returns 7 or 30. PHP cannot tell `[0 => 'No', 1 => 'Yes']` from the list `['No', 'Yes']`, so when your keys count up from 0, add `byKey: true`.

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
    $orders = $importer->read($file->path);      // your code: look into the file
    table($orders);

    if (button('Import ' . count($orders) . ' orders', confirm: 'Add them to the shop?')) {
        $importer->save($orders);
        $file->forget();                         // done: delete the file
        success('Imported ' . $file->name . '.');
        rerun();
    }
}
```

The file is an `Upload` with `name`, `size`, `path`, `contents()`, `saveTo()` and `forget()`. With `multiple: true` the field takes up to 20 files and returns a list, which is empty at first. A new file takes the place of the old one in a field for one file, and is added in a field for several.

**The file is still there on the next run.** A tool runs again on every interaction, so the library keeps the file on the server, and the page shows its name and size with a "Remove" link. The user can look at a preview, change other inputs and confirm, all with the same file.

**Call `forget()` when you are done.** Otherwise a file waits until the user removes or replaces it, the tool is reset, the field is no longer drawn, or a day has passed. To keep a file, copy it with `saveTo($path)` to a place of your own. `saveTo()` refuses to overwrite a file unless you pass `overwrite: true`.

The files wait in a folder of their own inside PHP's temp folder, open to the PHP user only. Name another folder with `uploads:` in `serve()` or `configure()`, outside the folders your web server serves. Files of users who never came back are deleted when they are a day old, while other files arrive. On a quiet server call `Runio\ScriptUi\clean_uploads()` from a daily job.

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

### Links that fill in fields

`/refund?customer-email=ava@example.com` opens the tool with that field filled in. A link can only fill plain fields. It can never press a button or tick a row. What a link filled in is not saved until the user changes or submits something.

## Use it in your application

**A folder of tools.** Put this in a script behind your web server:

```php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/bootstrap.php';            // starts your session, connects your database

Runio\ScriptUi\serve(
    __DIR__ . '/tools',
    auth: fn () => $_SESSION['admin_email'] ?? null,   // who is asking, or null to refuse
    log: fn (array $entry) => $auditLog->write($entry), // called for every button a user presses and every file a user downloads
    name: 'Back office',
);
```

Every PHP file in the folder becomes a tool, listed on an index page. The first comment in a file is its title, more comment lines are its description. Files that start with `_` are helpers and are never served.

With a login check, error details go to the error log and not into the page. Pass `debug: true` where you develop.

By default tools are chosen with `?_tool=refund`. With `base: '/admin/tools'` they get clean addresses such as `/admin/tools/refund` (send those addresses to the script in your web server configuration).

**One script on its own.** A script that the web server runs directly works too. Call `Runio\ScriptUi\configure(auth: ...)` in a file every tool includes, before anything is drawn. See [`examples/direct/hello.php`](examples/direct/hello.php).

### Languages

The texts the library writes itself ("Yes", "Cancel", "Reset", "No rows.") come in English, Arabic and German. Each page follows the language its title is written in, so a tool with an Arabic title is Arabic throughout and runs right to left. Call `title()` first: what is drawn before it does not know the language yet.

To use one language on every page, set it:

```php
Runio\ScriptUi\serve(__DIR__ . '/tools', language: 'de');
```

Your own words for built-in texts, or a language that is not built in, go into `texts:`. All texts and their ids are in [`resources/texts/en.php`](resources/texts/en.php). What you leave out stays English.

```php
Runio\ScriptUi\serve(
    __DIR__ . '/tools',
    language: 'fr',
    texts: ['fr' => ['confirm.yes' => 'Oui', 'confirm.no' => 'Annuler', 'page.reset' => 'Réinitialiser']],
);
```

`configure()` takes the same two settings. `button(yes:, no:)` and `table(empty:)` still set a text for one place. A Hebrew title turns the page right to left as well; its built-in texts stay English until you pass Hebrew ones.

Pages that are not a tool have no title to follow: the list of tools and pages such as "Not allowed" use `language:`, or else the language `name:` is written in.

## What the library does for safety

- **No login check, no outside access.** Without `auth`, tools only answer to a browser on the computer they run on. The request must come from a loopback address, with a local host name, and must not look passed on (proxy headers, HTTP/1.0, `Connection: close`). No check of this kind can see through every proxy or tunnel, so anything that can be reached from outside needs `auth`.
- **Forged requests are refused.** Every form carries a token. A button only counts on a form that was sent from the page itself. The "Reset" link carries a code of its own.
- **Everything is escaped.** Labels, table cells and messages are text, never HTML. Output printed with `echo` is shown as text too.
- **A strict Content-Security-Policy** on every page: no inline styles, no inline handlers, nothing from other sites, no framing.
- **A download is a file, never a page.** It is sent with the order to save it, never as HTML or a script, and it may do nothing if it is opened in the browser after all. Its name is made safe, also when it contains something a user typed. In CSV that the library writes, formula cells are defused. Every download goes to the log with the name and the size of the file. When the log cannot be written (the `log` callback throws or returns false), the file is not sent.
- **An uploaded file is out of reach.** It lies in a folder that only the PHP user reads, under a random name that says nothing about it. The page names it by a random handle that is not that name and only counts for the session, the tool and the field the file was uploaded to. No request can name a path, a file of another user or a file of another tool. The name the user gave is cleaned before it is shown or logged, and it is never used on the disk. The library does not serve uploads and does not run them.
- **Selections come from your data.** `table()` returns rows from the array you passed in. The browser only says which rows are ticked, and a tick never moves to another row or another table. A row that the search of a table hides is not returned, and the search reads only the columns that are shown.
- **A column you leave out stays out.** What a table puts into the page about a row, besides the cells it shows, is made with a secret that never leaves the session. Neither the id of a row nor a column you left out with `columns:` can be read from the page, or guessed by trying values against it.
- **Files are found by listing the folder.** An address can never point outside it.
- **Error details stay private.** They are shown only while no login check is set, because then nobody but your own computer gets in. With a login check they go to the error log unless you pass `debug: true`. PHP warnings and notices are kept out of the page in the same way.

What stays your job: decide who may do what (`user()` tells you who is asking), and never change data outside an `if (button(...))` block, because the script also runs on every plain page view.

## Limits of this first version

- Uploads have no progress bar and cannot be resumed. A field holds up to 20 files and a session 200. The kind of a file is judged by the ending of its name only.
- Uploaded files wait in PHP's temp folder unless you set `uploads:`. They are closed to other system users, but another PHP application that runs as the same user can read them.
- While a tool works on a request, PHP holds the session, and other requests of the same user wait. That shows with a large upload that a tool reads for a long time.
- A table gets all its rows from the script and searches, sorts and pages them itself, on every run. That is quick up to about twenty thousand rows: on a laptop a search took 0.1 seconds, and so did sorting by a text column. For more, filter in the database first.
- Charts are bar and line charts with one axis and at most 8 series.
- State lives in the PHP session. Two tabs of the same tool share saved inputs.
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

They drive the example tools in a real browser: the refund flow from the first key press to the audit log, downloads, uploads up to a file that is dragged onto the page, the charts, the Arabic page, a dark theme, a phone-sized window and the page without JavaScript. They start their own two servers on ports 8775 and 8776 and work on a copy of the example tools, so a demo that is running keeps its data.

## License

MIT. See [LICENSE](LICENSE).
