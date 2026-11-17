// Walks the module graph of the live entry page's Encore entry (assets/live_entry.js, competition toolkit C2) and prints
// what tests/LiveEntryBundleTest.php checks, as JSON:
//   modules         - every module the entry reaches (paths relative to the project root)
//   packages        - the npm packages it imports (bare specifiers)
//   dynamicImports  - modules with an `import(` (a lazy chunk - missing offline, the service worker precaches only
//                     the files entrypoints.json lists)
//   minifiedBytes / gzipBytes - the reached modules minified with terser (Encore's minifier) and gzipped: the size the
//                     page pays, independent of whether public/build holds a dev or a production build
// Reads source text only - imports are found with the same regular expressions for every module; the entry's modules
// are plain ES modules with static imports at the top.

import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const require = createRequire(path.join(root, 'package.json'));
const { minify } = require('terser');

const IMPORT_FROM = /(?:^|[;\s])(?:import|export)\s[^;'"`]*?\sfrom\s*['"]([^'"]+)['"]/g;
const IMPORT_BARE = /(?:^|[;\s])import\s*['"]([^'"]+)['"]/g;
const DYNAMIC_IMPORT = /(?:^|[^.\w$])import\s*\(/;

function stripComments(source) {
    return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:'"`\\])\/\/[^\n]*/g, '$1');
}

function packageEntry(specifier, from) {
    const parts = specifier.split('/');
    const name = specifier.startsWith('@') ? parts.slice(0, 2).join('/') : parts[0];
    const subPath = parts.slice(specifier.startsWith('@') ? 2 : 1).join('/');
    const manifestPath = createRequire(from).resolve(`${name}/package.json`);
    const directory = path.dirname(manifestPath);

    if (subPath !== '') {
        return { name, file: path.join(directory, subPath) };
    }

    const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));

    return { name, file: path.join(directory, manifest.module ?? manifest.main ?? 'index.js') };
}

const seen = new Map();
const packages = new Set();
const dynamicImports = [];
const queue = [path.join(root, 'assets', 'live_entry.js')];

while (queue.length > 0) {
    const file = queue.shift();

    if (seen.has(file)) {
        continue;
    }

    const source = fs.readFileSync(file, 'utf8');
    seen.set(file, source);
    const code = stripComments(source);

    if (DYNAMIC_IMPORT.test(code)) {
        dynamicImports.push(path.relative(root, file));
    }

    for (const pattern of [IMPORT_FROM, IMPORT_BARE]) {
        for (const match of code.matchAll(pattern)) {
            const specifier = match[1];

            if (specifier.startsWith('.')) {
                queue.push(path.resolve(path.dirname(file), specifier));
            } else {
                const resolved = packageEntry(specifier, file);
                packages.add(resolved.name);
                queue.push(resolved.file);
            }
        }
    }
}

let minifiedBytes = 0;
const minified = [];

for (const [file, source] of seen) {
    const result = await minify(source, { module: true, compress: { drop_console: true } });
    minifiedBytes += Buffer.byteLength(result.code);
    minified.push(result.code);
}

process.stdout.write(JSON.stringify({
    modules: [...seen.keys()].map((file) => path.relative(root, file)),
    packages: [...packages].sort(),
    dynamicImports,
    minifiedBytes,
    gzipBytes: zlib.gzipSync(minified.join('\n'), { level: 9 }).length,
}));
