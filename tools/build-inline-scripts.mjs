// Builds the inline <head> scripts every page carries (docs/features/navigation README §4.12 "Weight", implementation
// plan risk 8): the readable ES5 sources in assets/head/ (the platform flags, templates/_head_flags.html.twig) and
// assets/inline/ (the asset-failure and stale-document beacons, base.html.twig) are minified with terser into
// templates/_inline_scripts/, which the templates inline with source(). The output is committed; sources.json records
// the hash of each source and of its build, and tests/InlineScriptsBuildTest.php fails when a source changed without a
// new build, or when a build was edited by hand (the next build would silently drop that edit: edit the source).
//
//   npm run inline-scripts      (or: node tools/build-inline-scripts.mjs)
//
// ES5 in, ES5 out, comments dropped, names mangled inside each script's own function only. Single quotes, so the
// strings tests look for read the same ('data-os', 'msp:nav-layout'). A source never holds `</script` or `<!--`.

import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { minify } from 'terser';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = 'templates/_inline_scripts';

/** built file => source */
const SCRIPTS = {
    'platform_os.js': 'assets/head/platform_os.js',
    'standalone.js': 'assets/head/standalone.js',
    'nav_layout.js': 'assets/head/nav_layout.js',
    'asset_failure.js': 'assets/inline/asset_failure.js',
    'stale_document.js': 'assets/inline/stale_document.js',
};

const OPTIONS = {
    ecma: 5,
    compress: { passes: 2 },
    mangle: true,
    format: { comments: false, quote_style: 1 },
};

const manifest = {};
mkdirSync(join(ROOT, OUT), { recursive: true });

for (const [built, source] of Object.entries(SCRIPTS)) {
    const code = readFileSync(join(ROOT, source), 'utf8');
    const result = await minify(code, OPTIONS);

    if (typeof result.code !== 'string' || /<\/script|<!--/i.test(result.code)) {
        throw new Error(`${source}: no safe output`);
    }

    writeFileSync(join(ROOT, OUT, built), result.code);
    manifest[built] = {
        source,
        sha256: createHash('sha256').update(code).digest('hex'),
        build_sha256: createHash('sha256').update(result.code).digest('hex'),
    };
    console.log(`${source} ${Buffer.byteLength(code)} B → ${OUT}/${built} ${Buffer.byteLength(result.code)} B`);
}

writeFileSync(join(ROOT, OUT, 'sources.json'), `${JSON.stringify(manifest, null, 4)}\n`);
