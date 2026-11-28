# 4. A table of 100,000 rows

`table($rows)` gets all its rows from the script, and it searches, sorts and pages them itself. That is quick up to about twenty thousand rows. Beyond that the rows should stay in the database, and the table should ask for one page at a time.

<!--@include: @/diagrams/table-query.md-->

## The tool of the demo

The demo has such a table over 100,000 orders. The tool itself stays short:

<<< @/../examples/tools/archive.php{12-19}

![The order archive of the demo, searched for "haddad": a table of 25 orders with a search box, headers that sort, and a line that says which rows of how many are shown.](/shots/archive.png){.shot}

In place of the rows, the table gets a function (line 13). It calls that function with a `TableQuery` and gets the rows of one page back. `sortable:` names the columns whose headers can be clicked, and `count:` is a second function that says how many rows the search leaves.

In the demo both functions work the orders out from their numbers, so it needs no database. In a real tool they are two queries.

## With your database

From here on this page is the text of the README, word for word.

<!--@include: ../../README.md#big-table-->

Next: [A task that takes long](/tutorials/long-task).
