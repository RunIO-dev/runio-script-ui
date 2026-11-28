# 3. Inside your application

`script-ui serve` is for your own computer. It has no login check, so it answers nobody else. Colleagues need the tools on a server, behind the login of your application.

<!--@include: @/diagrams/hosting.md-->

There are two ways to get there. In plain PHP, one script behind your web server calls `serve()`, and the library sends the page. In Symfony or Laravel, a controller calls `respond()`, and the framework sends it.

The rest of this page is the text of the README, word for word, so it cannot say something else than the manual does.

## A folder of tools behind your web server

<!--@include: ../../README.md#host-folder-->

## Inside Symfony and Laravel

<!--@include: ../../README.md#host-frameworks-->

## Before colleagues get in

Go through the [checklist](/guide/safety#checklist): a login check, the folder outside the web root, who may open which tool, and a log.

Next: [A table of 100,000 rows](/tutorials/big-table).
