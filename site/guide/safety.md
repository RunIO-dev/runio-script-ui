# Safety and limits

A tool changes real data, so the library is strict where a mistake would hurt. This page says what it does by itself, what stays your job, and what this version cannot do.

## What a request passes

<!--@include: @/diagrams/gates.md-->

The first two questions are asked on every request, also when a page only refreshes itself. The third is asked whenever a form comes in: a changed input, a pressed button, an upload, a refresh. A page that is only opened sends no form. Only after that does the first line of your script run.

## What the library does

This list is the one of the README, word for word.

<!--@include: ../../README.md#safety-->

## Before colleagues get in {#checklist}

1. **Set `auth:`.** Without a login check the tools answer only on the computer they run on.
2. **Keep the folder of tools out of the web root.** Only the one script that calls `serve()` lies where the web server looks for files. Then try the address of a tool file: `/tools/refund.php` has to answer 403 or 404.
3. **Set `allow:`** when not every tool is for everybody who can log in.
4. **Change data only inside `if (button(...))`.** The script also runs when somebody changes a filter, and when a page refreshes by itself.
5. **Set `log:`** and keep the entries. They say who pressed what, with which inputs.
6. **Leave `debug:` off on the server.** With a login check, error details go to the error log and not into the page.
7. **Keep uploads small.** Set `maxSize:` and PHP's own limits as low as your tools need.
8. **In a framework:** end a run with `stop()` and never with `exit`. In Laravel, take the route out of the CSRF check and add `->block()`. [Inside your application](/tutorials/your-application) says why.

## Limits of this version

Also word for word from the README. [What comes next](/roadmap) says which of them may go.

<!--@include: ../../README.md#limits-->
