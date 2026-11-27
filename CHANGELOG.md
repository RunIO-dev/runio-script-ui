# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

First version. Requires PHP 8.2+ with the mbstring and session extensions, and no other package.

### Added
- A tool is one PHP script. It runs from top to bottom and runs again on every interaction.
- Text and messages: `title`, `heading`, `note`, `code`, `json`, `link`, `divider`, `metric`, `success`, `info`, `warning`, `error`.
- Inputs that return their current value: `text`, `textarea`, `number`, `select`, `checkbox`, `date_input`.
- `radio` shows every option and takes one. `multiselect` shows a tick box for every option and returns the ticked ones as a list. Both take their options the way `select` does, lay them out in a column, in several columns or in a row (`horizontal: true`), and can be filled in by a link.
- `table` with sorting, pages (`pageSize:`), a search box (`search: true`) and rows you can tick on any page, one by one or a page at a time. `chart` with bar and line charts drawn as SVG.
- `button` with an optional question before it acts, plus `columns`, `stop` and `rerun`.
- `file_input` takes files from the user: one or several, with limits on size and kind. A file stays on the server between the runs of a tool, in a folder only PHP reads, and the page names it by a random handle. `Upload` gives the tool `name`, `size`, `path`, `contents()`, `saveTo()` and `forget()`, and `rows()` reads an uploaded CSV file as rows, whichever spreadsheet wrote it. `uploads:` sets the folder, `clean_uploads()` deletes what was left behind.
- `download` hands the user a file: rows as CSV or JSON, or any text. `csv` writes rows as CSV with a choice of columns and separator. Cells that a spreadsheet would run as a formula are defused.
- `serve()` for a folder of tools with an index page, `configure()` for a script on its own, and the `script-ui serve` command for local use.
- A login check and an audit log (`auth`, `log`), protection against forged requests, a strict Content-Security-Policy, and local-only access while no login check is set.
- Pages update in place with JavaScript and work as plain forms without it. A title in Arabic or Hebrew turns the page right to left.
- The library's own texts ("Yes", "Reset", "No rows.") in English, Arabic and German. A page follows the language of its title, `language:` sets one language for every page, and `texts:` replaces single texts or adds a language.
