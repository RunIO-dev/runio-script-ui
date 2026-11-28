import { readFileSync } from 'node:fs';
import { defineConfig } from 'vitepress';

const repository = 'https://github.com/RunIO-dev/runio-script-ui';

// The version the site speaks about is the newest one the changelog names.
const version = /^## \[(\d+\.\d+\.\d+)\]/m.exec(readFileSync(new URL('../../CHANGELOG.md', import.meta.url), 'utf8'))?.[1];
if (!version) {
    throw new Error('CHANGELOG.md names no released version.');
}

export default defineConfig({
    lang: 'en-US',
    title: 'Script UI',
    description: 'Turn one PHP script into an internal tool with a web page. No HTML, no JavaScript, no controller.',
    // Not pages: the tools that the pages show, the diagrams and texts that the pages read in, and the notes for whoever works on the site.
    srcExclude: ['samples/**', 'diagrams/**', 'tools/**', 'README.md'],
    // The tutorials name the address of the server on the reader's own computer.
    ignoreDeadLinks: [/^https?:\/\/localhost/],
    head: [
        ['link', { rel: 'icon', href: '/favicon.svg', type: 'image/svg+xml' }],
        ['meta', { name: 'theme-color', content: '#1b64be' }],
    ],
    markdown: {
        config(md) {
            // The README, the changelog and the roadmap are read into pages. Their links to files
            // of the repository ("examples/tools/coupon.php") lead to GitHub from here. The changelog
            // and the roadmap are pages of this site, so a link to one of them stays here.
            const own = { 'CHANGELOG.md': '/changelog', 'ROADMAP.md': '/roadmap' };
            md.core.ruler.push('repository-links', (state) => {
                for (const token of state.tokens) {
                    for (const child of token.children ?? []) {
                        const href = child.type === 'link_open' ? child.attrGet('href') ?? '' : '';
                        if (own[href] !== undefined) {
                            child.attrSet('href', own[href]);
                        } else if (/^(?:examples|e2e|src|resources|tests|bin|site)\/|^(?:README|LICENSE)(?:\.md)?(?:#|$)/.test(href)) {
                            child.attrSet('href', repository + '/blob/main/' + href);
                        }
                    }
                }
            });
        },
    },
    themeConfig: {
        logo: '/favicon.svg',
        nav: [
            { text: 'What it is for', link: '/guide/what-it-is-for' },
            { text: 'Tutorials', link: '/tutorials/first-tool' },
            { text: 'Examples', link: '/examples/' },
            { text: 'Diagrams', link: '/diagrams' },
            { text: version, items: [
                { text: 'What changed', link: '/changelog' },
                { text: 'What comes next', link: '/roadmap' },
                { text: 'Packagist', link: 'https://packagist.org/packages/runio/script-ui' },
            ] },
        ],
        sidebar: [
            { text: 'Understand it', items: [
                { text: 'What it is for', link: '/guide/what-it-is-for' },
                { text: 'How it works', link: '/guide/how-it-works' },
                { text: 'Safety and limits', link: '/guide/safety' },
            ] },
            { text: 'Tutorials', items: [
                { text: '1. Your first tool', link: '/tutorials/first-tool' },
                { text: '2. A tool that changes data', link: '/tutorials/change-data' },
                { text: '3. Inside your application', link: '/tutorials/your-application' },
                { text: '4. A table of 100,000 rows', link: '/tutorials/big-table' },
                { text: '5. A task that takes long', link: '/tutorials/long-task' },
            ] },
            { text: 'Look things up', items: [
                { text: 'Examples', link: '/examples/' },
                { text: 'All functions', link: '/reference/functions' },
                { text: 'All diagrams', link: '/diagrams' },
                { text: 'What changed', link: '/changelog' },
                { text: 'What comes next', link: '/roadmap' },
            ] },
        ],
        socialLinks: [{ icon: 'github', link: repository }],
        search: { provider: 'local' },
        outline: [2, 3],
        editLink: {
            // VitePress hands this function to the browser as text, so it can use nothing from outside itself.
            // Pages whose text lives somewhere else in the repository lead there.
            pattern: ({ filePath }) => {
                const sources = { 'reference/functions.md': 'src/functions.php', 'roadmap.md': 'ROADMAP.md', 'changelog.md': 'CHANGELOG.md' };

                return 'https://github.com/RunIO-dev/runio-script-ui/edit/main/' + (sources[filePath] ?? 'site/' + filePath);
            },
            text: 'Edit this page on GitHub',
        },
        footer: { message: 'Released under the MIT License.', copyright: 'runio/script-ui ' + version },
    },
});
