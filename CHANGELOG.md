# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- `respond()` hands the page back instead of sending it, for an application built on Symfony or Laravel: a controller makes the response of its framework from the status, the headers and the body. `session:` keeps what the tools keep in the session of the application. Tried inside Laravel 12 and 13 and Symfony 7.4 and 8.1.
- `variables:` in `serve()` gives every tool of a folder variables by name.
- `user()` and the log name a user object of Symfony or Laravel by its identifier (`getUserIdentifier()`, `getAuthIdentifier()`), when the login check returns one.
- The list of tools says when the folder of tools lies in the web root, where a web server runs a tool file by its name and past the checks of `serve()`. The note shows only where error details are shown.
- `allow:` in `serve()` and `configure()` says who may open which tool. It gets what the login check returned and the id of a tool. A tool that is not allowed is left out of the list and answers "Not allowed" before anything of it runs. Only a plain `true` opens a tool, and the question is asked on every request.

### Security
- What the library keeps in a session belongs to the user the login check named. When another name shows up in the same session, as after a login without a logout before it, the saved inputs, the uploaded files, the waiting messages and the token of the forms are dropped. Before, they went to whoever used the session next. A session that an older version began has no owner yet, so it starts from nothing once as well.

### Changed
- A served tool that ends PHP with `exit` gets its page sent at that moment, and no longer when PHP shuts down.
- The list of tools is at the base as `base:` is written. With `base: '/admin/tools'` the links to the list now lead to `/admin/tools` and no longer to `/admin/tools/`. End the base with a slash to keep the old address.

### Fixed
- After a fatal error in a served tool the page reports the failure also when another shutdown function raised a warning first. Before, that warning hid the error, and the half-drawn page was sent as if all was well.

## [0.1.0] - 2026-11-27

First version. Requires PHP 8.2+ with the mbstring and session extensions, and no other package.

### Added
- A tool is one PHP script. It runs from top to bottom and runs again on every interaction.
- Text and messages: `title`, `heading`, `note`, `code`, `json`, `link`, `divider`, `metric`, `success`, `info`, `warning`, `error`.
- Inputs that return their current value: `text`, `textarea`, `number`, `select`, `checkbox`, `date_input`.
- `radio` shows every option and takes one. `multiselect` shows a tick box for every option and returns the ticked ones as a list. Both take their options the way `select` does, lay them out in a column, in several columns or in a row (`horizontal: true`), and can be filled in by a link.
- `table` with sorting, pages (`pageSize:`), a search box (`search: true`) and rows you can tick on any page, one by one or a page at a time. `chart` with bar and line charts drawn as SVG.
- `button` with an optional question before it acts, plus `columns`, `stop` and `rerun`.
- `expander` puts a part of the page under a line that opens and closes it, and `tabs` puts parts behind headers with one of them in view. What is out of sight still runs: the inputs inside keep working, and opening a part or changing the tab needs no run of the tool. The page remembers what was left open.
- `refresh` lets a page run again by itself every few seconds, for dashboards and queues. It waits while the user is in the middle of something, stops while the page is out of sight or nobody is there, waits longer after failures, and never presses a button or changes what the page remembers.
- `field_error` says under an input what is wrong with what it holds, and `valid` tells whether anything was found wrong. After a pressed button the first wrong input gets the keyboard focus, and an expander or a tab that hides it comes into sight. Screen readers are told that the input is invalid and read the note with it. A file that a file field turns down is marked in the same way and counts for `valid`. The log entry of a press says what was found wrong.
- `file_input` takes files from the user: one or several, with limits on size and kind. A file stays on the server between the runs of a tool, in a folder only PHP reads, and the page names it by a random handle. `Upload` gives the tool `name`, `size`, `path`, `contents()`, `saveTo()` and `forget()`, and `rows()` reads an uploaded CSV file as rows, whichever spreadsheet wrote it. `uploads:` sets the folder, `clean_uploads()` deletes what was left behind.
- `download` hands the user a file: rows as CSV or JSON, or any text. `csv` writes rows as CSV with a choice of columns and separator. Cells that a spreadsheet would run as a formula are defused.
- `serve()` for a folder of tools with an index page, `configure()` for a script on its own, and the `script-ui serve` command for local use.
- A login check and an audit log (`auth`, `log`), protection against forged requests, a strict Content-Security-Policy, and local-only access while no login check is set.
- The help text of an input is read out with the input by software that reads the page.
- Pages update in place with JavaScript and work as plain forms without it. A title in Arabic or Hebrew turns the page right to left.
- The library's own texts ("Yes", "Reset", "No rows.") in English, Arabic and German. A page follows the language of its title, `language:` sets one language for every page, and `texts:` replaces single texts or adds a language.

[Unreleased]: https://github.com/RunIO-dev/runio-script-ui/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/RunIO-dev/runio-script-ui/releases/tag/v0.1.0
