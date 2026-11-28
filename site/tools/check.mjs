// Checks the built site. "npm run build" runs it after VitePress, and it ends with 1 and a list
// when something is wrong.
//
// VitePress stops at a link to a page that is not there, and at a picture that is not there. It
// does not notice what this looks for: a file that was to be read in and was not found, an anchor
// that nothing answers, a part of the README that is not marked (the whole README comes in then),
// and a tutorial that names a line of a demo tool that has moved.
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import config from '../.vitepress/config.mjs';

const site = fileURLToPath(new URL('..', import.meta.url));
const dist = join(site, '.vitepress/dist');
const repository = 'https://github.com/RunIO-dev/runio-script-ui';
const problems = [];
const read = (path) => readFileSync(path, 'utf8');

if (!existsSync(dist)) {
    throw new Error('Nothing is built yet: run "npm run build".');
}

// Every built page, by its path in the site ("guide/safety.html"), with the ids it holds.
const pages = new Map();
(function walk(folder) {
    for (const entry of readdirSync(folder, { withFileTypes: true })) {
        const path = join(folder, entry.name);
        if (entry.isDirectory()) {
            walk(path);
        } else if (entry.name.endsWith('.html')) {
            const html = read(path);
            pages.set(relative(dist, path).replaceAll('\\', '/'), { html, ids: new Set([...html.matchAll(/\sid="([^"]+)"/g)].map((match) => match[1])) });
        }
    }
})(dist);

// The file of the page that an address inside the site leads to.
const fileOf = (address) => (address.endsWith('/') ? address + 'index.html' : address).replace(/^\//, '');

// What GitHub makes of a heading of the README.
const anchor = (heading) => heading.trim().toLowerCase().replace(/[^\p{L}\p{N} _-]/gu, '').replace(/ /g, '-');
const readme = new Set([...read(join(site, '../README.md')).matchAll(/^#{1,6} (.+)$/gm)].map((match) => anchor(match[1])));
// No part that a page reads in holds a heading of the second level. A page that has all of them holds the whole README.
const chapters = [...read(join(site, '../README.md')).matchAll(/^## (.+)$/gm)].map((match) => anchor(match[1]));

for (const [name, { html, ids }] of pages) {
    const say = (problem) => problems.push(`${name}: ${problem}`);

    if (html.includes('Code snippet path not found')) say('a code file that was to be shown was not found');
    if (chapters.length > 0 && chapters.every((id) => ids.has(id))) say('holds the whole README: a part that it reads in is not marked there');
    // A page that failed while it was built comes out without its foot.
    if (name !== '404.html' && name !== 'index.html' && !html.includes('/edit/main/')) say('has no "Edit this page" link: the page did not build to its end');

    for (const [, picture] of html.matchAll(/<img[^>]+src="(\/[^"]+)"/g)) {
        if (!existsSync(join(dist, picture))) say(`the picture ${picture} is missing`);
    }

    // An anchor on this page, on another page of the site, and in the README on GitHub.
    for (const [, address, hash] of html.matchAll(/<a[^>]+href="(\/[^"#]*)?#([^"]+)"/g)) {
        const target = address === undefined ? ids : pages.get(fileOf(address))?.ids;
        if (!target?.has(decodeURIComponent(hash))) say(`links to ${address ?? ''}#${hash}, and nothing there has that id`);
    }
    for (const [, hash] of html.matchAll(new RegExp(`href="${repository}(?:/blob/main/README\\.md)?#([^"]+)"`, 'g'))) {
        if (!readme.has(hash)) say(`links to "#${hash}" of the README, and the README has no such heading`);
    }
}

// What the pages read in. A file that is not there leaves no trace in the built page, so the
// sources are asked: every "@include" names a file that is there, and a part that is marked in it.
(function walk(folder) {
    for (const entry of readdirSync(folder, { withFileTypes: true })) {
        const path = join(folder, entry.name);
        if (entry.isDirectory()) {
            if (!['node_modules', '.vitepress', 'diagrams', 'public'].includes(entry.name)) walk(path);
            continue;
        }
        // The notes for whoever works on the site are no page: they only describe how a page reads a file in.
        if (!entry.name.endsWith('.md') || path === join(site, 'README.md')) continue;
        for (const [, target] of read(path).matchAll(/<!--\s*@include:\s*(.*?)\s*-->/g)) {
            const [, file, part] = /^(.*?)(?:#([\w-]+))?(?:\{\d*,\d*\})?$/.exec(target);
            const full = file.startsWith('@/') ? join(site, file.slice(2)) : join(dirname(path), file);
            const marked = (text) => ['region', 'endregion'].every((word) => text.split(/\r?\n/).includes(`<!-- #${word} ${part} -->`));
            if (!existsSync(full)) {
                problems.push(`${relative(site, path)}: reads in ${file}, and that file is not there`);
            } else if (part !== undefined && !marked(read(full))) {
                problems.push(`${relative(site, path)}: reads in the part "${part}" of ${file}, and that file marks no such part`);
            }
        }
    }
})(site);

// Every page that the menus name is there.
const links = [];
(function collect(items) {
    for (const item of items ?? []) {
        if (typeof item.link === 'string' && item.link.startsWith('/')) links.push(item.link);
        collect(item.items);
    }
})([...config.themeConfig.nav, ...config.themeConfig.sidebar]);
for (const link of new Set(links)) {
    if (!pages.has(fileOf(link.endsWith('/') ? link : link + '.html'))) problems.push(`the menu names ${link}, and that page was not built`);
}

// Every diagram is on the page of all diagrams, and every function on the page of all functions.
const drawn = (read(join(site, 'diagrams/draw.mjs')).match(/= drawing\(/g) ?? []).length;
const shown = (pages.get('diagrams.html')?.html.match(/class="sui-diagram"/g) ?? []).length;
if (drawn === 0 || drawn !== shown) {
    problems.push(`diagrams/draw.mjs draws ${drawn} diagrams, and the page of all diagrams shows ${shown}`);
}
const reference = pages.get('reference/functions.html')?.ids ?? new Set();
for (const [, name] of read(join(site, '../src/functions.php')).matchAll(/^function (\w+)\(/gm)) {
    if (!reference.has(name)) problems.push(`the page of all functions does not have ${name}()`);
}

// The tutorials name lines of the demo tools. When a tool changes, those numbers have to follow.
// Both ends of every range are named: a line that came in or went out in the middle moves the end.
const named = {
    'refund.php': { 16: "if ($email === '')", 20: '}', 24: '$picked = table(', 29: ');', 33: "button('Refund selected'", 39: 'rerun();', 40: '}' },
    'coupon.php': { 25: "if ($email !== '' && $customer === null)", 27: '}', 31: "if ($email === '')", 42: '}', 47: 'foreach ($coupons', 56: 'if (valid())', 60: '}' },
    '_config.php': { 17: '// Called for every button', 19: "'log' =>", 26: '},' },
    'archive.php': { 12: '$picked = table(', 13: 'Archive::page', 19: ');' },
    'export.php': { 13: "'waiting' ||", 15: 'refresh(2)', 17: "'waiting'", 22: '}', 24: "button('Stop the export'", 27: '}', 41: "'Start the export'", 44: 'rerun();', 45: '}' },
};
for (const [file, lines] of Object.entries(named)) {
    const code = read(join(site, '../examples/tools', file)).split('\n');
    for (const [number, text] of Object.entries(lines)) {
        if (!(code[number - 1] ?? '').includes(text)) {
            problems.push(`a tutorial names line ${number} of examples/tools/${file}, and that line no longer holds: ${text}`);
        }
    }
}

if (problems.length > 0) {
    process.stderr.write(`The built site has ${problems.length} problem(s):\n` + problems.map((problem) => '  - ' + problem).join('\n') + '\n');
    process.exit(1);
}
process.stdout.write(`The built site is sound: ${pages.size} pages, ${shown} diagrams, ${readme.size} headings of the README to link to.\n`);
