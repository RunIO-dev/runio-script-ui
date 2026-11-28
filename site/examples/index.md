# Examples

The demo is a small back office of a shop, in ten tools. Each one is a single PHP file. The pictures on this page are taken from the running demo, and the sources are the files of the repository as they are.

![The list of tools of the demo, "Shop back office": ten cards, each with the name of a tool and a line that says what it does.](/shots/demo-list.png){.shot}

The demo is not part of the Composer package. Run it from a copy of the repository:

```bash
git clone https://github.com/RunIO-dev/runio-script-ui.git
cd runio-script-ui
composer install
php bin/script-ui serve examples/tools
```

Then open <http://localhost:8765>. The demo keeps its data in `examples/var/`, and the audit log has a button that brings the sample data back.

## Change something

### Refund orders {#refund}

Find the orders of a customer, tick the ones to refund, and say yes. It shows a table that returns the ticked rows, and a button that asks before it acts. The [second tutorial](/tutorials/change-data) walks through it.

![The refund tool with two ticked orders and the question "Refund 2 orders?" with the buttons "Yes" and "Cancel".](/shots/refund.png){.shot}

::: details The source: examples/tools/refund.php
<<< @/../examples/tools/refund.php
:::

### Give a coupon {#coupon}

A form that checks what was typed, and says under each field what is wrong with it. Some checks run while the user fills the form in, and the rest when the button is pressed.

![The coupon tool after a press on "Give coupon": red notes under the fields "Customer email" and "Reason" say what is missing.](/shots/coupon.png){.shot}

::: details The source: examples/tools/coupon.php
<<< @/../examples/tools/coupon.php
:::

### Ship orders {#ship}

All paid orders, ten on a page, with a search over all of them. A tick stays when the page of the table is turned, so one press can ship orders from several pages.

![The tool "Ship orders": a search box, a table of paid orders with a tick box in every row, and the pages of the table under it.](/shots/ship.png){.shot .cut}

::: details The source: examples/tools/ship.php
<<< @/../examples/tools/ship.php
:::

## Look something up

### Customers {#customers}

Filters in three columns, tick boxes for the countries, and a table. Tick one customer and the page shows their orders and their record in two tabs, a link that opens the refund tool with the email filled in, and a download of what the filters left.

![The customer list, searched for "omar": one customer is ticked, and under the table stand a download button, a link to the refund tool, and two tabs with the orders and the record of that customer.](/shots/customers.png){.shot .cut}

::: details The source: examples/tools/customers.php
<<< @/../examples/tools/customers.php
:::

### Order archive {#archive}

A table over 100,000 orders that holds one page at a time. It asks a function for the rows of the page it shows. The [fourth tutorial](/tutorials/big-table) shows the same with a database.

![The order archive, searched for "haddad": a search box and a table of orders with tick boxes and headers that sort.](/shots/archive.png){.shot .cut}

::: details The source: examples/tools/archive.php
<<< @/../examples/tools/archive.php
:::

## Bring data in and out

### Import orders {#import}

Upload a CSV file, look at what it holds, and only then add the orders to the shop. The file waits on the server while the user looks at it, and nothing is saved before the button is pressed.

![The import tool after an upload: the file "orders.csv" with a link "Remove", a table with the two orders the file holds, and a button "Import 2 orders".](/shots/import.png){.shot}

::: details The source: examples/tools/import.php
<<< @/../examples/tools/import.php
:::

### Export orders {#export}

A task that takes half a minute: start it, watch how far it is, stop it, fetch the file. The page looks at the task every two seconds. The [fifth tutorial](/tutorials/long-task) walks through it.

![The export while it runs: a bar that is about a third full, the words "900 of 2,400 orders" beside it, and a button "Stop the export".](/shots/export.png){.shot}

::: details The source: examples/tools/export.php
<<< @/../examples/tools/export.php
:::

## Watch something

### Sales dashboard {#dashboard}

Four numbers with their change, two charts and the latest orders. The page runs again every 30 seconds while somebody looks at it, and it waits while they are in the middle of something.

![The sales dashboard: a choice of the period, four numbers (revenue, orders, average order and refunds) with their change, a bar chart of the revenue per day and a line chart of orders and refunds.](/shots/dashboard.png){.shot .cut}

::: details The source: examples/tools/dashboard.php
<<< @/../examples/tools/dashboard.php
:::

### Audit log {#audit}

Every pressed button and every download of the demo: who it was, in which tool, with which inputs, and whether a question was answered with yes. The tool only reads the lines that the `log:` setting of the demo writes.

![The audit log: a table with the time, the user, the tool, the button, whether it was confirmed, and the inputs of three presses.](/shots/audit.png){.shot}

::: details The source: examples/tools/audit.php
<<< @/../examples/tools/audit.php
:::

## In another language

### An Arabic page {#arabic}

A tool with an Arabic title is Arabic throughout. The page runs from right to left, and the texts that the library writes itself, such as "Reset" and the search box of the table, are Arabic too. German is built in as well, and any other language can be passed in.

![An Arabic tool: the title, the labels and the headers of the table are Arabic, and the page runs from right to left.](/shots/orders-ar.png){.shot .cut}

::: details The source: examples/tools/orders-ar.php
<<< @/../examples/tools/orders-ar.php
:::
