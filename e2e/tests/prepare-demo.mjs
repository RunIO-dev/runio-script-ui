// Copies the example tools into e2e/.demo before the server starts.
//
// The tools keep their data next to their folder (../var). Served from the copy, they get
// data of their own: every run starts with the same sample orders, and a demo that is
// running from examples/ keeps what it has.
//
// The tools in e2e/tools go into the same folder. They only exist for the tests: they show
// what no example shows, such as a field for several files. Their names start with "Test:".
import { cpSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { DEMO_DIR, EXAMPLES_DIR, TEST_TOOLS_DIR } from './support.mjs';

rmSync(DEMO_DIR, { recursive: true, force: true });
cpSync(join(EXAMPLES_DIR, 'tools'), join(DEMO_DIR, 'tools'), { recursive: true });
cpSync(TEST_TOOLS_DIR, join(DEMO_DIR, 'tools'), { recursive: true });
