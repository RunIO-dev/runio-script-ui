// Draws the diagrams of the site as plain SVG, with native shapes and nothing else.
//
//   node diagrams/draw.mjs        (from site/)
//
// It writes one file for each diagram, <name>.md: the figure with its caption. A page reads it
// in with <!--@include: @/diagrams/<name>.md-->. Those files are made, not kept: change a diagram here. The colours come from the page: every line and every
// text is currentColor, so a diagram follows the light and the dark theme. The parts that are
// Script UI itself carry the class "a" (accent), which .vitepress/theme/custom.css colours.
import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const esc = (text) => String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const cls = (...names) => {
    const list = names.filter(Boolean).join(' ');
    return list ? ` class="${list}"` : '';
};

function drawing(id, width, height, label) {
    const parts = [];
    const add = (part) => { parts.push(part); };

    const d = {
        text(x, y, lines, { size = 13, anchor = 'middle', muted = false, bold = false, accent = false, mono = false, gap } = {}) {
            const list = Array.isArray(lines) ? lines : [lines];
            const step = gap ?? Math.round(size * 1.3);
            const attrs = `x="${x}" y="${y}" text-anchor="${anchor}" font-size="${size}" fill="currentColor" stroke="none"`
                + (bold ? ' font-weight="600"' : '') + (muted ? ' opacity="0.72"' : '') + cls(accent && 'a', mono && 'm');
            add(list.length === 1
                ? `<text ${attrs}>${esc(list[0])}</text>`
                : `<text ${attrs}>${list.map((line, i) => `<tspan x="${x}" dy="${i === 0 ? 0 : step}">${esc(line)}</tspan>`).join('')}</text>`);

            return d;
        },
        // A box with a title in the middle and, under it, smaller lines.
        box(x, y, w, h, title, { sub = [], accent = false, dashed = false, round = 8, size = 13, mono = false } = {}) {
            add(`<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${round}" fill="currentColor" fill-opacity="0.045" stroke-opacity="0.6"`
                + cls(accent && 'a') + (dashed ? ' stroke-dasharray="5 4"' : '') + '/>');
            const titles = Array.isArray(title) ? title : [title];
            const subs = Array.isArray(sub) ? sub : [sub];
            let ty = y + h / 2 - (titles.length * 17 + subs.length * 15) / 2 + 13;
            for (const line of titles) {
                d.text(x + w / 2, ty, line, { bold: true, accent, size, mono });
                ty += 17;
            }
            for (const line of subs) {
                d.text(x + w / 2, ty - 1, line, { size: 11.5, muted: true });
                ty += 15;
            }

            return d;
        },
        ellipse(cx, cy, rx, ry, title, { accent = false } = {}) {
            add(`<ellipse cx="${cx}" cy="${cy}" rx="${rx}" ry="${ry}" fill="currentColor" fill-opacity="0.045" stroke-opacity="0.6"${cls(accent && 'a')}/>`);

            return d.text(cx, cy + 4.5, title, { size: 12.5, accent });
        },
        // The stick figure of a use case diagram. (cx, y) is the middle of its head.
        actor(cx, y, name) {
            add(`<circle cx="${cx}" cy="${y}" r="9"/>`);
            add(`<path d="M${cx} ${y + 9}V${y + 36}M${cx - 15} ${y + 20}H${cx + 15}M${cx} ${y + 36}L${cx - 12} ${y + 56}M${cx} ${y + 36}L${cx + 12} ${y + 56}"/>`);

            return d.text(cx, y + 76, name, { bold: true });
        },
        line(x1, y1, x2, y2, { dashed = false, faint = false } = {}) {
            add(`<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}"` + (faint ? ' stroke-opacity="0.45"' : '') + (dashed ? ' stroke-dasharray="5 4"' : '') + '/>');

            return d;
        },
        // A path with an arrowhead at its end. "label" stands at (lx, ly).
        arrow(path, { dashed = false, accent = false, label, lx, ly, anchor = 'middle', size = 12, mono = false, muted = false } = {}) {
            add(`<path d="${path}"${cls(accent && 'a')} marker-end="url(#${id}-arrow${accent ? '-a' : ''})"` + (dashed ? ' stroke-dasharray="5 4"' : '') + '/>');
            if (label !== undefined) {
                d.text(lx, ly, label, { size, anchor, mono, muted });
            }

            return d;
        },
        // Writes <id>.md: the figure with its caption, ready to be read into a page.
        save(caption) {
            const marker = (suffix, accent) => `<marker id="${id}-arrow${suffix}" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">`
                + `<path d="M0 0L10 5L0 10z" fill="currentColor" stroke="none"${cls(accent && 'a')}/></marker>`;
            // No empty line anywhere: in markdown an empty line would end the block of HTML.
            const figure = [
                '<figure class="diagram">',
                `<svg class="sui-diagram" viewBox="0 0 ${width} ${height}" width="${width}" height="${height}" role="img" aria-label="${esc(label)}" xmlns="http://www.w3.org/2000/svg"`
                + ' fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">',
                `<defs>${marker('', false)}${marker('-a', true)}</defs>`,
                ...parts,
                '</svg>',
                `<figcaption>${esc(caption)}</figcaption>`,
                '</figure>',
            ].join('\n');
            writeFileSync(fileURLToPath(new URL(`./${id}.md`, import.meta.url)), figure + '\n');
        },
    };

    return d;
}

// ------------------------------------------------------------------ who does what
{
    const d = drawing('use-cases', 760, 500, 'Use cases: a developer writes tools and says who may open them, colleagues use them, your application checks the login and holds the data, and a worker does long work.');
    d.box(200, 14, 360, 472, '', { round: 12 });
    d.text(380, 38, 'Script UI, inside your application', { bold: true, accent: true });

    const cases = [
        [74, 'Write a tool as one PHP script'],
        [122, 'Say who may open which tool'],
        [170, 'Read who pressed what, and when'],
        [250, 'Open a tool from the list'],
        [298, 'Search, sort and page through data'],
        [346, 'Change data with a button'],
        [394, 'Send a file in, fetch a file'],
        [442, 'Watch a task that takes long'],
    ];
    for (const [y, title] of cases) {
        d.ellipse(380, y, 150, 19, title);
    }

    d.actor(70, 86, 'A developer');
    for (const y of [74, 122, 170]) {
        d.line(92, 112, 230, y, { faint: true });
    }
    d.actor(70, 306, 'A colleague');
    d.text(70, 398, 'support, finance, ops', { size: 11.5, muted: true });
    for (const y of [250, 298, 346, 394, 442]) {
        d.line(92, 332, 230, y, { faint: true });
    }

    d.box(606, 96, 140, 56, 'Your application', { sub: ['login, rights, data'] });
    d.line(606, 118, 530, 122, { faint: true });
    d.line(606, 140, 530, 346, { faint: true });
    d.box(606, 414, 140, 56, 'A worker', { sub: ['queue job, cron'] });
    d.line(606, 442, 530, 442, { faint: true });
    d.save('Who does what. A developer writes a tool and says who may open it. Colleagues use it. Your application checks the login and holds the data, and a worker does the long work.');
}

// ------------------------------------------------------------------ by hand, and as one script
{
    const d = drawing('by-hand', 760, 300, 'A small internal page built by hand is a route, a controller, a template, validation with a form token, and JavaScript. With Script UI it is one script.');
    d.text(185, 26, 'A small internal page, by hand', { bold: true });
    const parts = [
        ['A route', 'the address of the page'],
        ['A controller', 'reads the request, calls your code'],
        ['A template', 'the HTML of the form and of the result'],
        ['Validation and a form token', 'what may be typed, and that the form is yours'],
        ['JavaScript', 'for what changes without a reload'],
    ];
    for (const [i, [name, what]] of parts.entries()) {
        d.box(20, 42 + i * 48, 330, 40, name, { sub: [what] });
    }
    // The brace that gathers the five parts.
    d.line(360, 42, 372, 42).line(372, 42, 372, 274).line(372, 274, 360, 274);
    d.arrow('M372 158H428', { accent: true, label: 'becomes', lx: 401, ly: 150, size: 11.5 });

    d.text(585, 26, 'The same page, with Script UI', { bold: true });
    d.box(430, 122, 310, 72, 'tools/refund.php', { sub: ['the inputs, the checks, the work and', 'the result, from top to bottom'], accent: true, mono: true });
    d.text(585, 224, ['The form, its token, the escaping', 'and the design come with the library.'], { size: 12, muted: true });
    d.save('The same small page, built by hand and written as one script.');
}

// ------------------------------------------------------------------ one click, from start to end
{
    const d = drawing('one-click', 760, 560, 'One click: the browser sends the whole form, Script UI checks the request and runs your script from the top, the functions return what the user entered, and the new page goes back.');
    const [B, S, T] = [100, 330, 560];
    d.box(B - 75, 16, 150, 40, 'The browser');
    d.box(S - 75, 16, 150, 40, 'Script UI', { accent: true });
    d.box(T - 75, 16, 150, 40, 'Your script');
    for (const x of [B, S, T]) {
        d.line(x, 56, x, 544, { dashed: true, faint: true });
    }

    d.arrow(`M${B} 110H${S}`, { label: ['the whole form: every input,', 'and the button that was pressed'], lx: (B + S) / 2, ly: 86 });
    d.arrow(`M${S} 146H${S + 26}V166H${S + 2}`, { accent: true, label: ['checks the login, the tool', 'and the token of the form'], lx: S + 38, ly: 152, anchor: 'start' });
    d.arrow(`M${S} 214H${T}`, { accent: true, label: 'runs it from the top', lx: (S + T) / 2, ly: 206 });

    d.arrow(`M${T} 262H${S}`, { label: "text('Customer email')", lx: (S + T) / 2, ly: 254, mono: true });
    d.arrow(`M${S} 292H${T}`, { dashed: true, accent: true, label: 'what the user typed', lx: (S + T) / 2, ly: 284 });
    d.arrow(`M${T} 340H${S}`, { label: "button('Refund')", lx: (S + T) / 2, ly: 332, mono: true });
    d.arrow(`M${S} 370H${T}`, { dashed: true, accent: true, label: 'true, in this run only', lx: (S + T) / 2, ly: 362 });

    d.arrow(`M${T} 408H${T + 26}V428H${T + 2}`, { label: ['does the work:', 'your code, your data'], lx: T + 38, ly: 414, anchor: 'start' });
    d.arrow(`M${T} 472H${S}`, { dashed: true, label: 'the end of the script', lx: (S + T) / 2, ly: 464 });
    d.arrow(`M${S} 512H${B}`, { accent: true, label: 'the new page', lx: (B + S) / 2, ly: 504 });
    d.text(B + 12, 534, 'swaps the content, no reload', { size: 11.5, muted: true, anchor: 'start' });
    d.save('One click, from start to end. Every interaction sends the whole form, and the script runs again from the top.');
}

// ------------------------------------------------------------------ what a request passes
{
    const d = drawing('gates', 760, 236, 'Before a line of your script runs, every request is asked who is asking and whether they may open this tool. A form that comes in must carry the token of the page as well.');
    const xs = [20, 205, 390, 575];
    d.box(xs[0], 44, 150, 64, 'Who is asking?', { sub: ['auth:'], accent: true });
    d.box(xs[1], 44, 150, 64, ['May they open', 'this tool?'], { sub: ['allow:'], accent: true });
    d.box(xs[2], 44, 150, 64, 'Is the form ours?', { sub: ['asked when a form comes', 'in: it carries a token'], accent: true });
    d.box(xs[3], 44, 150, 64, 'Your script runs', { sub: ['from top to bottom'] });
    d.text(xs[0] + 75, 24, 'every request, also a click', { size: 11.5, muted: true });
    for (const x of xs.slice(0, 3)) {
        d.arrow(`M${x + 150} 76H${x + 183}`, { label: 'yes', lx: x + 167, ly: 68, size: 11.5 });
        d.arrow(`M${x + 75} 108V160`, { dashed: true, label: 'no', lx: x + 88, ly: 138, size: 11.5, anchor: 'start' });
    }
    d.box(xs[0], 162, 150, 54, 'Refused', { sub: ['nobody is logged in'], dashed: true });
    d.box(xs[1], 162, 150, 54, 'Refused', { sub: ['"Not allowed"'], dashed: true });
    d.box(xs[2], 162, 150, 54, 'Refused', { sub: ['a forged request'], dashed: true });
    d.arrow(`M${xs[3] + 75} 108V160`);
    d.box(xs[3], 162, 150, 54, 'The page');
    d.save('What a request passes before a line of your script runs. A page that is only opened sends no form, so the third question is not asked of it.');
}

// ------------------------------------------------------------------ three ways to host
{
    const d = drawing('hosting', 760, 356, 'Three ways to host: on your computer and behind your web server Script UI sends the page itself. Inside Symfony or Laravel it hands the page back and the framework sends it.');
    const columns = [
        { x: 20, head: 'On your computer', first: ["PHP's own server", 'script-ui serve tools'], call: ['serve()', 'sends the page itself'], who: ['No login check:', 'only this computer gets in.'] },
        { x: 265, head: 'Behind your web server', first: ['Your web server', 'runs public/admin.php'], call: ['serve(auth: ...)', 'sends the page itself'], who: ['Your own session says', 'who is asking.'] },
        { x: 510, head: 'Inside Symfony or Laravel', first: ['The framework', 'a route and a controller'], call: ['respond()', 'hands the page back'], who: ['The login, the session and the', 'cookies of the framework.'] },
    ];
    for (const [i, column] of columns.entries()) {
        const cx = column.x + 115;
        d.text(cx, 26, column.head, { bold: true });
        d.box(column.x, 42, 230, 52, column.first[0], { sub: [column.first[1]] });
        d.box(column.x, 138, 230, 52, column.call[0], { sub: [column.call[1]], accent: true, mono: true });
        d.box(column.x, 234, 230, 52, 'tools/*.php', { sub: ['your scripts, outside the web root'], mono: true });
        if (i < 2) {
            d.arrow(`M${cx} 94V136`, { label: 'the request', lx: cx + 10, ly: 120, anchor: 'start', size: 11.5 });
        } else {
            d.arrow(`M${cx - 22} 94V136`, { label: 'the request', lx: cx - 32, ly: 120, anchor: 'end', size: 11.5 });
            d.arrow(`M${cx + 22} 138V96`, { accent: true, label: 'status, headers, body', lx: cx + 32, ly: 120, anchor: 'start', size: 11.5 });
        }
        d.arrow(`M${cx} 190V232`, { accent: true, label: 'runs one of them', lx: cx + 10, ly: 216, anchor: 'start', size: 11.5 });
        d.text(cx, 314, column.who, { size: 12, muted: true });
    }
    d.save('Three ways to host. The difference is who sends the page, and where the login comes from.');
}

// ------------------------------------------------------------------ a task that takes long
{
    const d = drawing('long-task', 760, 300, 'A task that takes long: the tool writes a row and hands the work over, a worker does it and writes down how far it is, and the page reads those numbers every two seconds.');
    d.box(20, 104, 210, 70, 'Your tool', { sub: ['refresh(2) and progress()'], accent: true });
    d.box(275, 104, 210, 70, 'Your storage', { sub: ['one row: state, done, total'] });
    d.box(530, 104, 210, 70, 'A worker', { sub: ['queue job, cron, command'] });

    d.arrow('M125 104C125 58 370 58 370 102', { accent: true, label: 'Start: writes the row', lx: 248, ly: 54 });
    d.arrow('M390 104C390 58 635 58 635 102', { label: 'picks the task up', lx: 512, ly: 54 });
    d.arrow('M635 174C635 220 390 220 390 176', { label: 'writes how far it is, as it goes', lx: 512, ly: 236 });
    d.arrow('M370 174C370 220 125 220 125 176', { accent: true, dashed: true, label: 'every 2 seconds: reads the numbers', lx: 248, ly: 236 });

    d.text(125, 274, 'a run takes a fraction of a second', { size: 11.5, muted: true });
    d.text(635, 274, 'one run, for minutes or hours', { size: 11.5, muted: true });
    d.save('A task that takes long. The page never does the work: it looks at it every two seconds.');
}

// ------------------------------------------------------------------ the states of a task
{
    const d = drawing('task-states', 760, 186, 'The states of a task and what the tool shows in each: waiting and running show a bar, finished a message and the file, failed an error, stopped a warning. A task can be stopped while it waits and while it runs.');
    d.box(20, 68, 150, 50, 'waiting', { sub: ["progress('Waiting')"], round: 25 });
    d.box(250, 68, 170, 50, 'running', { sub: ['progress(label, done, total)'], round: 25, accent: true });
    d.box(590, 8, 150, 50, 'finished', { sub: ['success(), download()'], round: 25 });
    d.box(590, 68, 150, 50, 'failed', { sub: ['error()'], round: 25 });
    d.box(590, 128, 150, 50, 'stopped', { sub: ['warning()'], round: 25 });

    d.arrow('M170 93H248', { label: 'a worker', lx: 209, ly: 84, size: 11.5 });
    d.text(209, 110, 'takes it', { size: 11.5 });
    d.arrow('M420 93H588', { label: 'the worker gave up', lx: 512, ly: 85, size: 11.5 });
    d.arrow('M440 93V33H588', { label: 'all of it is done', lx: 512, ly: 25, size: 11.5 });
    d.arrow('M440 93V153H588', { label: 'somebody asked to stop', lx: 512, ly: 145, size: 11.5 });
    // A task that still waits can be stopped as well: its line joins the one from "running".
    d.line(95, 118, 95, 153).line(95, 153, 440, 153);
    d.save('The states of a task, and what the tool shows in each.');
}

// ------------------------------------------------------------------ a table that reads from the database
{
    const d = drawing('table-query', 760, 336, 'A table that reads from the database: the browser only names a page, a column and a search text. The table hands your function a TableQuery with values of your own, and gets one page of rows back.');
    d.box(30, 20, 220, 48, 'The browser', { sub: ['shows 25 rows of 100,000'] });
    d.box(30, 136, 220, 48, 'table()', { sub: ['trusts nothing as it comes'], accent: true, mono: true });
    d.box(30, 268, 220, 48, 'Your function', { sub: ['one query for one page'] });

    d.arrow('M110 68V134', { label: 'the request', lx: 100, ly: 108, anchor: 'end', size: 11.5 });
    d.arrow('M110 184V266', { accent: true, label: 'a TableQuery', lx: 100, ly: 232, anchor: 'end', size: 11.5 });
    d.arrow('M170 268V186', { dashed: true, label: '25 rows', lx: 180, ly: 232, anchor: 'start', size: 11.5 });
    d.arrow('M170 136V70', { dashed: true, accent: true, label: 'the page', lx: 180, ly: 108, anchor: 'start', size: 11.5 });

    d.text(290, 86, 'The browser sends', { anchor: 'start', bold: true, size: 12.5 });
    d.text(290, 105, ['page 3', 'sort by column 2', 'search: müller'], { anchor: 'start', size: 12, mono: true, gap: 16 });
    d.text(450, 105, ['a number', 'a place in your sortable: list', 'a text'], { anchor: 'start', size: 12, muted: true, gap: 16 });

    d.text(290, 200, 'Your function gets a TableQuery', { anchor: 'start', bold: true, size: 12.5, accent: true });
    d.text(290, 219, ["search   'müller'", "sort     'c.name'", 'limit 25, offset 50'], { anchor: 'start', size: 12, mono: true, gap: 16 });
    d.text(450, 219, ['to bind as a parameter', 'the value you wrote, not the browser', 'whole numbers the table worked out'], { anchor: 'start', size: 12, muted: true, gap: 16 });

    d.text(290, 296, 'WHERE ... LIKE ? ORDER BY c.name, id LIMIT 25 OFFSET 50', { anchor: 'start', size: 12, mono: true });
    d.save('A table that reads from the database. Nothing the browser sends reaches your query as it came.');
}

// ------------------------------------------------------------------ is it the right tool?
{
    const d = drawing('right-tool', 760, 392, 'Is Script UI the right tool? For people inside, behind a login. Not for a screen that mostly lists and edits records: an admin generator fits that. Yes for a task with a few inputs, a button and a result.');
    d.box(30, 20, 300, 56, ['Is it for people inside,', 'behind a login?'], { round: 28 });
    d.box(30, 128, 300, 56, ['Is it mostly records to list,', 'create, edit and delete?'], { round: 28 });
    d.box(30, 236, 300, 56, ['Is it a task: a few inputs,', 'a button, a result?'], { round: 28 });

    d.arrow('M180 76V126', { label: 'yes', lx: 192, ly: 106, anchor: 'start', size: 11.5 });
    d.arrow('M180 184V234', { label: 'no', lx: 192, ly: 214, anchor: 'start', size: 11.5 });
    d.arrow('M180 292V334', { label: 'no', lx: 192, ly: 318, anchor: 'start', size: 11.5 });

    d.arrow('M330 48H448', { label: 'no', lx: 389, ly: 40, size: 11.5 });
    d.box(450, 20, 290, 56, 'A page of your application', { sub: ['Script UI is not for the public'] });
    d.arrow('M330 156H448', { label: 'yes', lx: 389, ly: 148, size: 11.5 });
    d.box(450, 128, 290, 56, 'An admin generator', { sub: ['EasyAdmin, Filament, Nova'] });
    d.arrow('M330 264H448', { accent: true, label: 'yes', lx: 389, ly: 256, size: 11.5 });
    d.box(450, 236, 290, 56, 'Script UI', { sub: ['one script for one task'], accent: true });
    d.box(30, 336, 300, 48, 'A page you build by hand', { sub: ['full control, more work'] });
    d.save('Three questions that tell whether Script UI fits.');
}
