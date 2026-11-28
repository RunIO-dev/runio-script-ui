# Roadmap

What may come next in Script UI. What is done already is in the [changelog](CHANGELOG.md).

An item starts under "Proposed". It moves to "Ready" when the maintainer has approved it and the names it brings are settled. The order under "Ready" is the order of the work. Each item is built on a branch of its own and arrives as a pull request.

## Ready

Nothing yet.

## In review

Nothing yet.

## Proposed

Not approved yet. Every name in this list is a working name.

### In a tool

- **R1: Choices that search in your data.** `select()` and `multiselect()` get all their options from the script. A customer out of 50,000 cannot be chosen that way. A function would get what was typed and return the options that match.
- **R3: Several inputs sent together.** Every changed choice runs the script again. A long form with a slow query behind it wants one run, when its button is pressed.
- **R4: A button in every row of a table.** Today the user ticks a row and presses a button under the table. For one row that is a step too many.
- **R5: Tabs that run only when they are open.** Every tab runs on every run. A link cannot open a tab or an expander yet.
- **R6: A refresh for one part of a page.** `refresh()` runs the whole script and swaps the whole content.
- **R7: More inputs.** A time, a range of dates, a slider, a switch, a password.
- **R8: Pictures and formatted text.** `image()` and `markdown()`.
- **R10: More kinds of charts.** Stacked bars and areas.
- **R11: More built-in languages.** French, Spanish and Turkish next to English, German and Arabic.

### For the developer

- **R2: Try a tool in a unit test.** Fill inputs, press a button and read the messages from PHPUnit. Today a tool can only be tried in a browser test.
- **R17: Packages for Laravel and Symfony.** A route, the session and the rights wired up around `respond()`, in place of the lines of the manual.
- **R14: Recipes for php-fpm with nginx and Apache**, tried in CI.

### Safety and access

- **R9: Stricter uploads.** Judge a file by what is in it and not by its name, and limit the bytes a session may hold.
- **R15: More for screen readers.** Say the note under a field while the user types, and give the signs in front of messages words.

### Behind the scenes

- **R12: The table example on MySQL and PostgreSQL**, in CI.
- **R13: Browser tests on Firefox and WebKit.**
- **R16: The website published by CI** with every release, with fresh pictures. CI builds and checks it already, and publishing is done by hand. Later a public demo with data of its own for every visitor.
- **R18: A failed state in the export of the demo.**
- **R19: A short film of one click** on the first page of the website.

## Not planned

- **A queue or a worker inside the library.** A tool starts your job and shows its numbers. The job stays yours.
- **Pages for the public.** A tool is for people inside, behind a login.
