# The website of Script UI

What is on <https://script-ui.runio.dev>: what the library is for, tutorials, examples and diagrams. It is a static site made with [VitePress](https://vitepress.dev). Nothing in this folder is part of the Composer package.

```bash
cd site
npm install
npm run dev        # the site on http://localhost:5173, rebuilt while you write
npm run build      # the finished site in .vitepress/dist, and a check of it
```

## Where a text lives

Every fact has one home. A page of the site either holds it or reads it in.

| On the site | Its source | How it gets there |
|---|---|---|
| The pages under `guide/` and `tutorials/`, the examples, the first page | the `.md` file itself | |
| Code on a page | `samples/*.php` and `../examples/tools/*.php` | read in with `<<<`, never copied |
| Parts marked "word for word from the README" | `../README.md`, between `<!-- #region name -->` and `<!-- #endregion name -->` | `<!--@include: ../../README.md#name-->` |
| All functions | the doc comments in `../src/functions.php` | `tools/reference.mjs` writes `reference/functions.md` |
| The diagrams | `diagrams/draw.mjs` | it writes one `diagrams/<name>.md` each |
| What changed, What comes next | `../CHANGELOG.md`, `../ROADMAP.md` | read in whole |
| The pictures in `public/shots/` | the running demo and `samples/` | `cd ../e2e && node site-shots.mjs` |
| The live pictures of the first page | `.vitepress/theme/live/` | `<LiveClick />` and `<LiveTask />` on a page, see below |

`npm run dev` and `npm run build` write the generated files first. They are not kept in git. The pictures are kept, because taking them needs PHP and a browser.

The version in the menu is the newest one that the changelog names.

## The check after a build

`tools/check.mjs` runs at the end of `npm run build` and fails when:

- a file that was to be read in was not found, or a region is missing in the README;
- a picture is missing, or a link on a page points to an anchor that is not there;
- a link to a heading of the README names a heading the README does not have;
- a diagram or a function is missing on its page;
- a tutorial names a line of a demo tool that no longer holds what the tutorial says;
- the first page does not carry its two live pictures.

## The live pictures of the first page

The first page has two pictures that a visitor can use: one click in `samples/hello.php`, and a task that takes long. Their files are in `.vitepress/theme/live/`:

- `click.js` and `task.js` say what happens, as plain data and without a browser. `npm test` tries them, and `npm run build` starts with it.
- `LiveClick.vue` and `LiveTask.vue` draw it, and `live.css` is their look.
- `hello.data.js` reads the lines of `samples/hello.php` when the site is built. A step of the picture finds its line by its text, and the build stops when that line is gone.

The pictures are simulations, so they must say what the library does. A second set of tests uses them in a browser, and runs the real `samples/hello.php` with the same inputs to compare:

```bash
npm run build
cd ../e2e && npx playwright test -c site
```

Run it after a change to the pictures, to `samples/hello.php`, or to how the library sends a form. It needs PHP and the browser of the other browser tests.

## What `npm audit` says

It reports advisories for esbuild and Vite, which VitePress 1 builds on, and VitePress 1 has no version without them. All of them are about the development server that `npm run dev` starts. So start it on your own computer only, never with `--host`, and stop it when you are done. The built site is plain files, and nothing of Node serves it.

## Publishing

The build is a folder of plain files. Copy it to the folder that the web server reads for the domain:

```bash
npm run build
rsync -rlt --chmod=D755,F644 --delete .vitepress/dist/ <host>:<folder of the domain>/
```

The command sets the modes of the files itself. Some disks report every file as writable by everybody, an NTFS disk under Linux for one, and `rsync -a` would carry that to the server. `--delete` removes what lies in the folder and is not part of the build, so the folder has to belong to the site alone.

Publish from a release tag, so that the site describes a version that people can install.
