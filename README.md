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

Open <http://localhost:8765>. The demo has five tools: refunds, a sales dashboard with charts, a customer list with filters, an Arabic page (right to left), and the audit log. The demo data lives in `examples/var/` and can be deleted at any time.

## How it works

A tool is a script that runs from top to bottom. Every time the user changes an input or presses a button, the page is submitted and **the script runs again**. Each input function returns the current value, so the script reads like a description of the page.

- `text()`, `select()`, `number()` and the other inputs return what the user entered.
- `button()` returns `true` only in the run that its click started. `if (button('Save')) { ... }` does the work once per click.
- `button('Delete', confirm: 'Delete 3 rows?')` returns `true` only after the user answered yes to exactly that question.
- `table($rows, selectable: true)` returns the rows the user ticked.
- `rerun()` loads the page again after an action, so a table above the button shows the new data. Messages written after the click are kept.

Inputs are saved per tool, so a reload keeps them. The "Reset" link in the header forgets them.

The page works without JavaScript as a plain form. With JavaScript it updates in place: changing an input reruns the script in the background and only the content is swapped.

## Functions

| Group | Functions |
|---|---|
| Text | `title` `heading` `note` `code` `json` `link` `divider` |
| Messages | `success` `info` `warning` `error` |
| Inputs | `text` `textarea` `number` `select` `checkbox` `date_input` |
| Data | `table` `chart` `metric` |
| Actions | `button` |
| Layout and flow | `columns` `stop` `rerun` |
| Hosting | `serve` `configure` `user` |

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

### Rows you can tick

`table($rows, selectable: true)` returns the ticked rows. A row is found again by its `id` column (name another one with `rowKey:`). Rows without an id are recognised by what is in them. A tick never moves to a different row: when its row is gone or has changed, the tick is dropped.

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
    log: fn (array $entry) => $auditLog->write($entry), // called for every button a user presses
    name: 'Back office',
);
```

Every PHP file in the folder becomes a tool, listed on an index page. The first comment in a file is its title, more comment lines are its description. Files that start with `_` are helpers and are never served.

With a login check, error details go to the error log and not into the page. Pass `debug: true` where you develop.

By default tools are chosen with `?_tool=refund`. With `base: '/admin/tools'` they get clean addresses such as `/admin/tools/refund` (send those addresses to the script in your web server configuration).

**One script on its own.** A script that the web server runs directly works too. Call `Runio\ScriptUi\configure(auth: ...)` in a file every tool includes, before anything is drawn. See [`examples/direct/hello.php`](examples/direct/hello.php).

## What the library does for safety

- **No login check, no outside access.** Without `auth`, tools only answer to a browser on the computer they run on. The request must come from a loopback address, with a local host name, and must not look passed on (proxy headers, HTTP/1.0, `Connection: close`). No check of this kind can see through every proxy or tunnel, so anything that can be reached from outside needs `auth`.
- **Forged requests are refused.** Every form carries a token. A button only counts on a form that was sent from the page itself. The "Reset" link carries a code of its own.
- **Everything is escaped.** Labels, table cells and messages are text, never HTML. Output printed with `echo` is shown as text too.
- **A strict Content-Security-Policy** on every page: no inline styles, no inline handlers, nothing from other sites, no framing.
- **Selections come from your data.** `table()` returns rows from the array you passed in. The browser only says which rows are ticked, and a tick never moves to another row or another table.
- **Files are found by listing the folder.** An address can never point outside it.
- **Error details stay private.** They are shown only while no login check is set, because then nobody but your own computer gets in. With a login check they go to the error log unless you pass `debug: true`. PHP warnings and notices are kept out of the page in the same way.

What stays your job: decide who may do what (`user()` tells you who is asking), and never change data outside an `if (button(...))` block, because the script also runs on every plain page view.

## Limits of this first version

- No file uploads and no download button (CSV export) yet. Until then a tool can send a file itself on a plain page view: when a parameter such as `?export=1` is set, close the output buffers, send the headers, print the file and `exit`. Offer it with `link('Download CSV', '/orders?export=1')`.
- Built-in texts such as "Yes", "Cancel" and "No rows." are English. The labels you pass can be in any language, and a title in Arabic or Hebrew turns the whole page right to left.
- Tables show the first 200 rows by default (`limit:`). There is no paging yet.
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

They drive the example tools in a real browser: the refund flow from the first key press to the audit log, the charts, the Arabic page, a dark theme, a phone-sized window and the page without JavaScript. They start their own two servers on ports 8775 and 8776 and work on a copy of the example tools, so a demo that is running keeps its data.

## License

MIT. See [LICENSE](LICENSE).
