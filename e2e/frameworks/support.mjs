import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

/** This folder: what is copied over the two fresh applications, and the tools they serve. */
export const FRAMEWORKS_DIR = fileURLToPath(new URL('./', import.meta.url));
/** The library itself. The applications install it from here. */
export const LIBRARY_DIR = fileURLToPath(new URL('../../', import.meta.url));
/**
 * Where the applications live. Not inside the repository: each of them holds a link back to
 * the library, and a folder that contains itself confuses more than one program.
 */
export const APPS_DIR = process.env.FRAMEWORK_APPS ?? join(tmpdir(), 'script-ui-framework-apps');

export const LARAVEL_PORT = Number(process.env.E2E_LARAVEL_PORT ?? 8781);
export const SYMFONY_PORT = Number(process.env.E2E_SYMFONY_PORT ?? 8782);

export const APPS = [
    { name: 'Laravel', folder: join(APPS_DIR, 'laravel'), port: LARAVEL_PORT, url: `http://127.0.0.1:${LARAVEL_PORT}` },
    { name: 'Symfony', folder: join(APPS_DIR, 'symfony'), port: SYMFONY_PORT, url: `http://127.0.0.1:${SYMFONY_PORT}` },
];
