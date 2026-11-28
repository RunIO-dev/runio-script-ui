# How it works

A tool is a script that runs from top to bottom. Every time the user changes an input or presses a button, the browser sends the whole form and **the script runs again**. Each function draws its part of the page and returns what the user did with it.

<!--@include: @/diagrams/one-click.md-->

## What the functions return

| You write | You get |
|---|---|
| `text('Customer email')` | what the user typed |
| `select('Status', ['paid', 'shipped'])` | one of your options, whatever the browser sent |
| `table($rows, selectable: true)` | the rows the user ticked |
| `file_input('Orders', types: ['csv'])` | the uploaded file, on every run until it is removed |
| `button('Save')` | `true` in the run that its click started, and only there |
| `button('Delete', confirm: 'Delete 3 rows?')` | `true` only after the user answered yes to exactly that question |

[All functions](/reference/functions) has the rest.

## Three things that follow

**The order of the code is the order of the page.** What you call first stands at the top. An `if` around a part decides whether it is there. An early `return` ends the page.

**Data changes behind a button.** The script also runs when somebody only changes a filter, and when a page refreshes by itself. Work that changes something belongs inside `if (button(...))`, which is true once for each click.

```php
if (button('Refund', confirm: 'Refund order ' . $order['id'] . '?')) {
    $refunds->create($order['id']);   // runs once, after a click and a yes
    success('Refunded.');
}
```

**The script keeps nothing between two runs.** A variable is gone when the run ends. What the user entered comes back through the input functions, because the library saves the inputs of every tool in the session. Everything else comes from your database, as in any PHP request.

## When the script runs again

| The user | The script runs again |
|---|---|
| chooses an option, ticks a box, picks a date, changes a number | at once |
| types into a text field | when they press Enter or leave the field |
| types into the search box of a table | after a short pause in the typing |
| presses a button | at once, and `button()` is true in that run |
| does nothing, on a page that calls `refresh(30)` | every 30 seconds, while the page is in view. It waits while the user is in the middle of something, and pauses after an hour that nobody touched it |

## What the user notices

- The page updates in place. With JavaScript only the content is swapped, and the keyboard focus stays where it was. Without JavaScript the page works as a plain form.
- The inputs survive a reload. The link "Reset" in the header forgets them.
- After an action, `rerun()` runs the script once more, so that a table above the button shows the new data. Messages written after the click are kept.

## The names of inputs

Every input and every button has a key. It names the form field and the saved value, and it is made from the label: `'Customer email'` becomes `customer-email`. Two inputs with the same label on one page are an error until each has a key of its own, as in `text('Name', key: 'billing-name')`. In a loop, build the key from the row:

```php
foreach ($orders as $order) {
    if (button('Refund', key: 'refund-' . $order['id'])) {
        $refunds->create($order['id']);
    }
}
```

The README says [why keys are not simply numbered](https://github.com/RunIO-dev/runio-script-ui#keys).

## Where to go from here

- [Your first tool](/tutorials/first-tool) builds a page in ten minutes.
- [A tool that changes data](/tutorials/change-data) shows the confirmation and the checks.
- [Safety and limits](/guide/safety) says what every request passes before your script runs.
