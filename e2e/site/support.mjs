import { fileURLToPath } from 'node:url';

// The tests of the website use ports of their own, next to those of the other suites.
export const SITE_PORT = Number(process.env.E2E_SITE_PORT ?? 8783);
export const SAMPLES_PORT = Number(process.env.E2E_SAMPLES_PORT ?? 8784);
export const SITE_URL = `http://127.0.0.1:${SITE_PORT}`;
export const SAMPLES_URL = `http://127.0.0.1:${SAMPLES_PORT}`;

/** The website: its sources, and where its build lies. */
export const SITE_DIR = fileURLToPath(new URL('../../site/', import.meta.url));
