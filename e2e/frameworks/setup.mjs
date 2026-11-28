// Sets up the two applications that frameworks/tests/frameworks.spec.mjs runs against: a fresh
// Laravel and a fresh Symfony, each with the library installed from this repository and one
// route that serves the tools in frameworks/tools.
//
//     node frameworks/setup.mjs                       (from e2e/)
//     npx playwright test -c frameworks
//
// The first run downloads both applications with Composer. That needs the network and takes a
// minute or two. They are kept outside the repository: in FRAMEWORK_APPS, or in a folder of the
// system temp folder. Every later run only copies the files of this folder over them, which is
// quick. So run it again after you changed one of them.
//
// LARAVEL and SYMFONY choose the versions, as Composer writes them: LARAVEL='^12.0' for
// example. Without them the newest ones that the PHP on this machine can run are taken.
// Laravel 13 needs PHP 8.3 and Symfony 8 needs PHP 8.4. Below that it is Laravel 12 and
// Symfony 7.4. An application that was set up with other versions is made anew.
import { execFileSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { APPS_DIR, FRAMEWORKS_DIR, LIBRARY_DIR } from './support.mjs';

const php = Number(execFileSync('php', ['-r', 'echo PHP_VERSION_ID;'], { encoding: 'utf8' }));
const wanted = {
    laravel: process.env.LARAVEL ?? (php >= 80300 ? '^13.0' : '^12.0'),
    symfony: process.env.SYMFONY ?? (php >= 80400 ? '8.1.*' : '7.4.*'),
};
const marker = join(APPS_DIR, 'versions.json');
const have = existsSync(marker) ? JSON.parse(readFileSync(marker, 'utf8')) : {};

const composer = (folder, ...args) => execFileSync('composer', [...args, '--no-interaction'], { cwd: folder, stdio: 'inherit' });

/** A fresh application, with the library installed from this repository as a link. */
function create(name, skeleton, ...more) {
    const folder = join(APPS_DIR, name);
    if (have[name] === wanted[name] && existsSync(join(folder, 'vendor', 'runio', 'script-ui'))) {
        return folder;
    }

    rmSync(folder, { recursive: true, force: true });
    mkdirSync(APPS_DIR, { recursive: true });
    composer(APPS_DIR, 'create-project', '--no-progress', `${skeleton}:${wanted[name]}`, name);
    for (const packages of more) {
        composer(folder, 'require', '--no-progress', ...packages);
    }
    composer(folder, 'config', 'repositories.script-ui', JSON.stringify({ type: 'path', url: LIBRARY_DIR, options: { symlink: true } }));
    composer(folder, 'require', '--no-progress', 'runio/script-ui:*@dev');

    return folder;
}

/** Changes one thing in a file of an application. Stops when the file is not what this script knows. */
function change(file, from, to, what, done = to) {
    const text = readFileSync(file, 'utf8');
    if (text.includes(done)) {
        return;
    }
    if (!from.test(text)) {
        throw new Error(`${file} is not what this script knows: ${what} could not be set.`);
    }
    writeFileSync(file, text.replace(from, to));
}

/** Copies the files of this folder over the application, and the tools it serves. */
function overlay(name, folder) {
    cpSync(join(FRAMEWORKS_DIR, name), folder, { recursive: true });
    rmSync(join(folder, 'tools'), { recursive: true, force: true });
    cpSync(join(FRAMEWORKS_DIR, 'tools'), join(folder, 'tools'), { recursive: true });
}

const laravel = create('laravel', 'laravel/laravel');
overlay('laravel', laravel);
// No database: the session and the cache are files. (Blocking a session needs a cache that can lock.)
change(join(laravel, '.env'), /^SESSION_DRIVER=.*$/m, 'SESSION_DRIVER=file', 'the session driver');
change(join(laravel, '.env'), /^CACHE_STORE=.*$/m, 'CACHE_STORE=file', 'the cache store');
// What the README asks for: the forms of the tools carry the token of the library, not Laravel's.
change(
    join(laravel, 'bootstrap', 'app.php'),
    /(->withMiddleware\(function \(Middleware \$middleware\)(?:: void)? \{\n)\s*\/\/\n/,
    "$1        $middleware->validateCsrfTokens(except: ['admin/tools', 'admin/tools/*']);\n",
    'the exception for the forms of the tools',
    "validateCsrfTokens(except: ['admin/tools', 'admin/tools/*'])",
);

const symfony = create('symfony', 'symfony/skeleton', ['symfony/security-bundle']);
overlay('symfony', symfony);
execFileSync('php', ['bin/console', 'cache:clear', '--no-interaction'], { cwd: symfony, stdio: 'inherit' });

writeFileSync(marker, JSON.stringify(wanted, null, 2) + '\n');
process.stdout.write(`Ready in ${APPS_DIR}: Laravel ${wanted.laravel} and Symfony ${wanted.symfony}.\n`);
