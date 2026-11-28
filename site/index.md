---
layout: home
title: Script UI
titleTemplate: Internal tools from one PHP script

hero:
  name: Script UI
  text: One PHP script is one internal tool.
  tagline: Like Streamlit, for PHP. Call text(), table() and button() from top to bottom, and the library draws the page. No HTML, no JavaScript, no controller.
  actions:
    - theme: brand
      text: Write your first tool
      link: /tutorials/first-tool
    - theme: alt
      text: What it is for
      link: /guide/what-it-is-for
    - theme: alt
      text: See the examples
      link: /examples/

features:
  - title: An input returns its value
    details: "<code>$email = text('Customer email')</code> gives you what the user typed. There is no form to build and no request to read."
  - title: A button is an if
    details: "<code>if (button('Refund', confirm: 'Refund 2 orders?'))</code> runs once for each click, after the user said yes."
  - title: Tables that select, search and page
    details: "Pass rows and get the ticked ones back. For 100,000 rows, pass a function that reads one page from your database."
  - title: Tasks that take long
    details: "<code>progress()</code> and <code>refresh()</code> show how far a worker is. The page keeps up by itself and leaves the user alone while they type."
  - title: Inside your application
    details: "Behind your own login, in plain PHP, Symfony or Laravel. You say who may open which tool, and every pressed button can go to your log."
  - title: Safe from the start
    details: "Form tokens, escaping and a strict Content-Security-Policy come with it. Without a login check a tool only answers on your own computer."
---

## This script is this page

<div class="side-by-side">
<div>

::: code-group
<<< @/samples/hello.php [tools/hello.php]
:::

</div>
<div>

![The page that the script makes: a field "Your name" that holds "Ava", a button "Greet", and under it the message "Hello, Ava."](/shots/hello.png){.shot}

</div>
</div>

A click sends the form, and **the script runs again from the top**. This time `text()` returns what was typed, `button()` returns `true`, and `success()` puts the message on the page. That is the whole model. [How it works](/guide/how-it-works) shows it step by step.

## Install it

```bash
composer require runio/script-ui
php vendor/bin/script-ui serve tools
```

It needs PHP 8.2 or newer and no other package. The second line starts the server that comes with PHP, for your own computer. [Inside your application](/tutorials/your-application) shows how colleagues get in.

## What people build with it

<div class="side-by-side">
<div>

![The refund tool of the demo: two orders of a customer are ticked, and a question asks "Refund 2 orders?" with the buttons "Yes" and "Cancel".](/shots/refund.png){.shot}

</div>
<div>

![An Arabic tool of the demo. The page runs from right to left, and the texts of the library are Arabic too.](/shots/orders-ar.png){.shot .cut}

</div>
</div>

- **Change something.** Refund orders, give a coupon, mark orders as shipped.
- **Look something up.** A customer list with filters, an archive of 100,000 orders.
- **Bring data in and out.** Import a CSV file, export one, download what a filter left.
- **Watch something.** A dashboard that keeps itself up to date, a long export with a bar.

Every one of them is in the [examples](/examples/), with a picture and its source. The texts of the library come in English, German and Arabic, and an Arabic title turns the page right to left.

## Where to start

- [What it is for](/guide/what-it-is-for), and when another tool fits better.
- [Your first tool](/tutorials/first-tool), in ten minutes.
- [Safety and limits](/guide/safety), for whoever has to approve it.
