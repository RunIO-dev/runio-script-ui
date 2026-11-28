# Roadmap

What comes next in Script UI. What is done already is in the [changelog](CHANGELOG.md).

An item starts under "Proposed". It moves to "Ready" when the maintainer has approved it. The order under "Ready" is the order of the work: one item at a time, each on a branch of its own, each as a pull request. A name that an item brings is a working name until the maintainer has confirmed it in that pull request.

## Ready

Approved on 2026-11-28, in this order. The reasons for the order:

- A small item comes first, to try the way of working on something that cannot do much harm.
- Then what makes every later item safer to build: tools that can be tried in a unit test.
- Then what people ask for first when they start to use the library.
- Proof on real servers and stricter uploads come before the long tail of new inputs, because they decide whether a team may use the library at all.

1. **R18: A failed state in the export of the demo.** The export can wait, run, finish and be stopped. It cannot fail, and the tutorial on long tasks has to say so. A task that fails shows `error()` in place of the bar.
2. **R2: Try a tool in a unit test.** Fill inputs, press a button and read the messages from PHPUnit. Today a tool can only be tried in a browser test.
3. **R4: A button in every row of a table.** Today the user ticks a row and presses a button under the table. For one row that is a step too many.
4. **R3: Several inputs sent together.** Every changed choice runs the script again. A long form with a slow query behind it wants one run, when its button is pressed.
5. **R14: Recipes for php-fpm with nginx and Apache**, tried in CI. So far the library was tried on PHP's own server only.
6. **R1: Choices that search in your data.** `select()` and `multiselect()` get all their options from the script. A customer out of 50,000 cannot be chosen that way. A function would get what was typed and return the options that match.
7. **R9: Stricter uploads.** Judge a file by what is in it and not by its name, and limit the bytes a session may hold.
8. **R7: More inputs.** A time, a range of dates, a slider, a switch, a password.
9. **R5: Tabs that run only when they are open.** Every tab runs on every run. A link cannot open a tab or an expander yet.
10. **R8: Pictures and formatted text.** `image()` and `markdown()`.
11. **R15: More for screen readers.** Say the note under a field while the user types, and give the signs in front of messages words.
12. **R13: Browser tests on Firefox and WebKit.**
13. **R12: The table example on MySQL and PostgreSQL**, in CI.
14. **R6: A refresh for one part of a page.** `refresh()` runs the whole script and swaps the whole content.
15. **R10: More kinds of charts.** Stacked bars and areas.
16. **R11: More built-in languages.** French, Spanish and Turkish next to English, German and Arabic.

## In review

Nothing yet.

## Proposed

Not approved yet. Each of these needs a decision that only the maintainer can make.

- **R16: The website published by CI** with every release, with fresh pictures. CI builds and checks it already, and publishing is done by hand. It needs a key for the server in the settings of the repository. Later a public demo with data of its own for every visitor.
- **R17: Packages for Laravel and Symfony.** A route, the session and the rights wired up around `respond()`, in place of the lines of the manual. Each would be a package and a repository of its own.

## Not planned

- **A queue or a worker inside the library.** A tool starts your job and shows its numbers. The job stays yours.
- **Pages for the public.** A tool is for people inside, behind a login.
