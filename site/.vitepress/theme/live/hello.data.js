import { readFileSync } from 'node:fs';
import { createMarkdownRenderer } from 'vitepress';
import { LINES } from './click.js';

// The lines of site/samples/hello.php for the live picture of the first page, read when the
// site is built. They are highlighted by the same renderer as the code on the pages, and every
// line that a step of the picture points at is found by its text. When the sample changes and
// such a line is gone, the build stops here.
export default {
    watch: ['../../../samples/hello.php'],

    async load(watched) {
        const file = watched.find((path) => path.endsWith('hello.php'));
        if (file === undefined) {
            throw new Error('The live picture of the first page needs site/samples/hello.php, and it is not there.');
        }

        const config = globalThis.VITEPRESS_CONFIG;
        const markdown = await createMarkdownRenderer(config.srcDir, config.markdown, config.site.base, config.logger);

        const source = readFileSync(file, 'utf8').replace(/\s+$/, '').split('\n');
        const code = /<code>([\s\S]*?)<\/code>/.exec(markdown.render('```php\n' + source.join('\n') + '\n```\n', {}));
        const drawn = code === null ? [] : code[1].split('\n');
        if (drawn.length !== source.length) {
            throw new Error(`hello.php has ${source.length} lines, and its highlighted form has ${drawn.length}. Did the renderer change what it writes?`);
        }

        const lines = source.map((text, at) => {
            const inner = /^<span class="line[^"]*">(.*)<\/span>$/.exec(drawn[at]);
            if (inner === null) {
                throw new Error(`Line ${at + 1} of hello.php is not highlighted as one line: ${drawn[at]}`);
            }

            return { html: inner[1], is: Object.keys(LINES).find((name) => LINES[name] === text.trim()) ?? null };
        });

        for (const [name, text] of Object.entries(LINES)) {
            const found = lines.filter((line) => line.is === name).length;
            if (found !== 1) {
                throw new Error(`The live picture points at the line "${text}" of hello.php. It stands there ${found} times, and it must stand there once.`);
            }
        }

        return lines;
    },
};
