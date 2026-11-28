# 1. Your first tool

You need PHP 8.2 or newer and Composer. At the end you have a page with an input, a button and a table that filters. It takes about ten minutes.

## Install

```bash
mkdir back-office && cd back-office
composer require runio/script-ui
mkdir tools
```

## One file

Put this into `tools/hello.php`:

::: code-group
<<< @/samples/hello.php [tools/hello.php]
:::

Serve the folder and open <http://localhost:8765>:

```bash
php vendor/bin/script-ui serve tools
```

![The list of tools: one entry, "Hello", with the description "My first tool."](/shots/list.png){.shot}

The list shows every PHP file of the folder, except those whose name starts with `_`: they are helpers. The first comment of a file is the name of the tool, and the comment lines after it describe it. Open "Hello", type a name and press the button:

<div class="side-by-side">
<div>

![The page before the click: an empty field "Your name" and a button "Greet".](/shots/hello-start.png){.shot}

</div>
<div>

![The page after the click: the field holds "Ava", and under the button stands the message "Hello, Ava."](/shots/hello.png){.shot}

</div>
</div>

## What happened

1. You typed a name and pressed "Greet". The browser sent the form.
2. The script ran again from the top. This time `text('Your name')` returned `'Ava'`, and `button('Greet')` returned `true`.
3. `success()` put the message on the page, and the browser swapped the new content in.

Now change the name and leave the field. The script runs again, but nobody pressed the button, so `button()` is `false` in that run and the message is gone. A button is true for one run only.

## Inputs that filter a table

A second file. Tools need no button at all:

::: code-group
<<< @/samples/orders.php [tools/orders.php]
:::

![The tool "Find orders": the field "Customer" holds "ha", and the table shows the two orders of Omar Haddad out of six.](/shots/orders.png){.shot}

Things to notice:

- **Nothing is wired up.** Type a part of a name and press Enter, or choose a status: the script runs again, and the table shows what is left.
- **`select()` returns one of your options**, whatever a browser sends. There is nothing to validate.
- **`table()` sorts** when a header is clicked, and it cuts a long list into pages.
- **`download()` hands over the rows as a CSV file.** A cell that a spreadsheet would take for a formula is made harmless.
- **A reload keeps the filter.** The inputs of a tool are saved in the session. "Reset" in the header forgets them.

## What you did not write

No route, no controller, no template, no form handling, no JavaScript and no CSS. The form token, the escaping and the page design came with the library.

Next: [A tool that changes data](/tutorials/change-data).
