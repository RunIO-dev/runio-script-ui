// Writes reference/functions.md from the doc comments of the library, so that the site and the
// code cannot say two different things.
//
//   node tools/reference.mjs        (from site/)
//
// It reads src/functions.php and the three classes a tool gets to hold. Nothing is written by
// hand: to change a text on that page, change the doc comment. It stops when it meets something
// it would have to leave out: a function or a public method without a doc comment, or a tag it
// does not know.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const repository = 'https://github.com/RunIO-dev/runio-script-ui';
const version = /^## \[(\d+\.\d+\.\d+)\]/m.exec(read('../../CHANGELOG.md'))?.[1];
if (!version) {
    throw new Error('CHANGELOG.md names no released version.');
}

// A doc comment without "/**" and "*/", as lines of text and as tags.
function parse(comment, where) {
    const lines = comment.split('\n').map((line) => line.replace(/^\s*\*? ?/, '').trimEnd());
    const text = [];
    const tags = [];
    for (const line of lines) {
        if (line.startsWith('@')) {
            tags.push(line);
        } else if (tags.length > 0) {
            tags[tags.length - 1] += ' ' + line.trim();
        } else {
            text.push(line);
        }
    }

    const doc = { text, params: [], returns: null, throws: [], notes: [], internal: false };
    for (const tag of tags.map((line) => line.trim())) {
        const [, name, rest] = /^@([\w-]+)\s*([\s\S]*)$/.exec(tag);
        if (name === 'param') {
            const param = /^(.+?)\s+((?:\.\.\.)?\$\w+)\s*([\s\S]*)$/.exec(rest);
            if (!param) {
                throw new Error(`${where}: cannot read "${tag}".`);
            }
            doc.params.push({ type: param[1], name: param[2], text: param[3] });
        } else if (name === 'return' || name === 'throws') {
            const end = typeEnd(rest);
            const typed = { type: rest.slice(0, end), text: rest.slice(end).trim() };
            if (name === 'return') {
                doc.returns = typed;
            } else {
                doc.throws.push(typed);
            }
        } else if (name === 'deprecated' || name === 'see') {
            doc.notes.push({ name: name === 'see' ? 'See' : 'Deprecated', text: rest });
        } else if (name === 'internal') {
            // Not for whoever writes a tool: what carries this tag stays off the page.
            doc.internal = true;
        } else {
            // A tag that is left out would leave the page looking whole while it says less than the code.
            throw new Error(`${where}: the tag "@${name}" is not one this script puts on the page.`);
        }
    }

    return doc;
}

// Where a type ends: at the first blank that is not inside <>, {} or ().
function typeEnd(text) {
    let depth = 0;
    for (let i = 0; i < text.length; i++) {
        if ('<{('.includes(text[i])) depth++;
        else if ('>})'.includes(text[i])) depth--;
        else if (text[i] === ' ' && depth === 0) return i;
    }

    return text.length;
}

// The page is markdown that Vue reads: a "<" would start a tag, and two braces a value. What
// stands between backticks is shown as it is, so it is left alone.
const escape = (text) => text.split(/(`[^`]*`)/).map((part, i) => (i % 2 === 1 ? part : part.replace(/</g, '&lt;').replace(/\{\{/g, '&#123;&#123;'))).join('');

// Lines of a doc comment as markdown. Lines that are indented by four blanks are a sample.
function prose(lines) {
    const out = [];
    for (let i = 0; i < lines.length; i++) {
        if (!/^ {4}\S/.test(lines[i])) {
            out.push(escape(lines[i]));
            continue;
        }
        let end = i;
        for (let j = i; j < lines.length; j++) {
            if (/^ {4}/.test(lines[j])) end = j;
            else if (lines[j] !== '') break;
        }
        out.push('', '```php', ...lines.slice(i, end + 1).map((line) => line.slice(4)), '```', '');
        i = end;
    }

    return out.join('\n').replace(/\n{3,}/g, '\n\n').trim();
}

// "- `$name` (`type`): what it is", without the colon where the comment says nothing about it.
const entry = (name, type, text) => `- \`${name}\` (\`${type}\`)${text ? ': ' + escape(text) : ''}`;

function describe(doc) {
    const out = [prose(doc.text)];
    if (doc.params.length > 0) {
        out.push(doc.params.map((param) => entry(param.name, param.type, param.text)).join('\n'));
    }
    if (doc.returns) {
        out.push(`**Returns** \`${doc.returns.type}\`${doc.returns.text ? ': ' + escape(doc.returns.text) : ''}`);
    }
    if (doc.throws.length > 0) {
        out.push('**Throws**\n\n' + doc.throws.map((thrown) => `- \`${thrown.type}\`${thrown.text ? ' ' + escape(thrown.text) : ''}`).join('\n'));
    }
    for (const note of doc.notes) {
        out.push(`**${note.name}** ${escape(note.text)}`);
    }

    return out.filter(Boolean).join('\n\n');
}

const fenced = (code) => '```php\n' + code + '\n```';
const comment = String.raw`\/\*\*((?:(?!\*\/)[\s\S])*)\*\/`;
// A signature ends with "{" on a line of its own, or after "): type" when it is written over several lines.
const signature = (start) => start + String.raw`\([\s\S]*?\)(?:: [^\n{]+?)?)\s*\{`;

// ------------------------------------------------------------------ the functions
const source = read('../../src/functions.php');
const groups = [...source.matchAll(/^\/\/ -+ (.+)$/gm)].map((match) => ({
    at: match.index,
    name: (match[1][0].toUpperCase() + match[1].slice(1)).replace(', ', ' and '),
    functions: [],
}));
const found = [...source.matchAll(new RegExp(comment + String.raw`\n` + signature(String.raw`(function (\w+)`), 'g'))];
const expected = (source.match(/^function /gm) ?? []).length;
if (found.length !== expected || groups.length === 0) {
    throw new Error(`src/functions.php has ${expected} functions, and ${found.length} of them were read with their doc comment.`);
}
for (const match of found) {
    const doc = parse(match[1], match[3] + '()');
    if (!doc.internal) {
        groups.findLast((group) => group.at < match.index).functions.push({ name: match[3], signature: match[2], doc });
    }
}

// ------------------------------------------------------------------ the classes a tool gets to hold
function classPage(path, intro) {
    const code = read('../../src/' + path);
    const head = new RegExp(comment + String.raw`\n(?:final )?class (\w+)`).exec(code);
    if (!head) {
        throw new Error(`src/${path}: no class with a doc comment.`);
    }
    const out = [`### ${head[2]} {#${head[2].toLowerCase()}}`, intro, prose(parse(head[1], head[2]).text)];

    // What the object holds: the properties of the constructor, with the types and the words of its doc comment.
    const made = new RegExp(String.raw`    ` + comment + String.raw`\n    public function __construct\(([\s\S]*?)\n    \)`).exec(code);
    const properties = [...(made?.[2] ?? '').matchAll(/public readonly (\S+) \$(\w+)/g)];
    if (properties.length > 0) {
        const told = new Map(parse(made[1], head[2] + '::__construct()').params.map((param) => [param.name, param]));
        out.push(properties.map(([, type, name]) => entry('$' + name, told.get('$' + name)?.type ?? type, told.get('$' + name)?.text)).join('\n'));
    }

    const methods = [...code.matchAll(new RegExp(String.raw`    ` + comment + String.raw`\n    ` + signature(String.raw`(public function (?!__)(\w+)`), 'g'))];
    const all = (code.match(/^    public function (?!__)\w+\(/gm) ?? []).length;
    if (methods.length !== all) {
        throw new Error(`src/${path} has ${all} public methods, and ${methods.length} of them were read with their doc comment.`);
    }
    for (const method of methods) {
        const doc = parse(method[1], head[2] + '::' + method[3] + '()');
        if (!doc.internal) {
            out.push(`**${method[3]}()**`, fenced(method[2]), describe(doc));
        }
    }

    return out.filter(Boolean).join('\n\n');
}

// ------------------------------------------------------------------ the page
const page = [
    '---\noutline: [2, 3]\n---',
    '# All functions',
    `Every function of the library, with the description from its source. This page is built from [\`src/functions.php\`](${repository}/blob/v${version}/src/functions.php) of version ${version}, so it cannot say something else than the code does.`,
    'Import the functions you use, or give the namespace a short name:',
    fenced("use function Runio\\ScriptUi\\{title, text, button, success};\n\nuse Runio\\ScriptUi as ui;      // then: ui\\title('Find orders');"),
    '| Group | Functions |\n|---|---|\n' + groups.map((group) => `| [${group.name}](#${slug(group.name)}) | ${group.functions.map((fn) => `[\`${fn.name}\`](#${fn.name})`).join(' ')} |`).join('\n'),
    ...groups.flatMap((group) => [
        `## ${group.name} {#${slug(group.name)}}`,
        ...group.functions.flatMap((fn) => [`### ${fn.name}() {#${fn.name}}`, fenced(fn.signature), describe(fn.doc)]),
    ]),
    '## Objects a tool gets to hold {#objects}',
    classPage('Upload.php', 'What `file_input()` returns.'),
    classPage('TableQuery.php', 'What a function gets that you pass to `table()` in place of the rows.'),
    classPage('Http/Response.php', 'What `respond()` returns.'),
].join('\n\n') + '\n';

function slug(name) {
    return name.toLowerCase().replace(/[^a-z0-9]+/g, '-');
}

mkdirSync(new URL('../reference/', import.meta.url), { recursive: true });
writeFileSync(fileURLToPath(new URL('../reference/functions.md', import.meta.url)), page);
