#!/usr/bin/env node
// The finish detector's files, served by us (docs/features/online-competitions/README.md §5.7 "Downloads"): MediaPipe
// tasks-vision - its ESM bundle and the wasm/ directory - from the npm registry, and the pose + gesture models from
// Google's model bucket. Every byte is checked against the pins below before anything is written; the files land in
// <root>/<DIRECTORY>/ laid out like the CDN paths config/services.php `onlineDetectorAssets` names, so
// OnlineDetectorAssets only swaps the base (ONLINE_DETECTOR_BASE_URL=/online-detector/<DIRECTORY>).
//
//   node bin/fetch-online-detector.mjs [--precompress] [<root>]   <root> defaults to public/online-detector
//   node bin/fetch-online-detector.mjs --pins                     the pins as JSON (OnlineDetectorAssetsTest)
//
// The Dockerfile's `online-detector` stage runs it with --precompress (a .br at quality 11 and a .gz next to each file,
// for Caddy's `precompressed` - .docker/online-detector/online-detector.caddyfile). In dev it is optional: an empty
// ONLINE_DETECTOR_BASE_URL loads the pinned CDN URLs; run this and set ONLINE_DETECTOR_BASE_URL in .env.local to try
// the local path (public/online-detector/ is gitignored).
//
// A new version is a new DIRECTORY, never new bytes under an old one (served immutable for a year): bump the pins here,
// `@mediapipe/tasks-vision` in package.json / package-lock.json (the same tarball and integrity), the CDN URLs of
// `onlineDetectorAssets` (config/services.php), DETECTOR_ASSETS of assets/online/hands_up_signal.js (the bench) and
// ONLINE_DETECTOR_BASE_URL in the Dockerfile - OnlineDetectorAssetsTest fails until all of them agree.

import { createHash } from 'node:crypto';
import { chmod, mkdir, readFile, rename, rm, utimes, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { brotliCompress, constants as zlib, gunzipSync, gzip } from 'node:zlib';

const HERE = dirname(fileURLToPath(import.meta.url));

export const TASKS_VISION = Object.freeze({
    version: '1.1.0',
    tarball: 'https://registry.npmjs.org/@mediapipe/tasks-vision/-/tasks-vision-1.1.0.tgz',
    // = package-lock.json's `integrity` of node_modules/@mediapipe/tasks-vision
    integrity: 'sha512-ZJqh0wMKOINorfSffvDAxzyO1//c+FiG3IkzOdNDnLqvBB3DbMfj0bB/tys2SNf9S914sgoIbIpF4HTOi336cg==',
    // The bundle the worker import()s and every WASM loader + binary FilesetResolver may pick: classic or module
    // worker (detector.worker.js isModuleWorker()), with or without WASM SIMD
    files: Object.freeze([
        'vision_bundle.mjs',
        'wasm/vision_wasm_internal.js',
        'wasm/vision_wasm_internal.wasm',
        'wasm/vision_wasm_module_internal.js',
        'wasm/vision_wasm_module_internal.wasm',
        'wasm/vision_wasm_nosimd_internal.js',
        'wasm/vision_wasm_nosimd_internal.wasm',
    ]),
    licence: 'Apache-2.0 (package.json "license") - Copyright Google LLC, https://github.com/google-ai-edge/mediapipe',
});

// Google's numbered paths (never `latest`); the bucket's MD5s are in tools/online-bench/fetch-models.mjs
export const MODELS = Object.freeze([
    Object.freeze({
        file: 'pose_landmarker_lite.task',
        url: 'https://storage.googleapis.com/mediapipe-models/pose_landmarker/pose_landmarker_lite/float16/1/pose_landmarker_lite.task',
        bytes: 5777746,
        sha256: '59929e1d1ee95287735ddd833b19cf4ac46d29bc7afddbbf6753c459690d574a',
        licence: 'Apache-2.0 - model card "MediaPipe BlazePose GHUM 3D" (Google, 2021-04-16): '
            + 'https://storage.googleapis.com/mediapipe-assets/Model%20Card%20BlazePose%20GHUM%203D.pdf',
    }),
    Object.freeze({
        file: 'gesture_recognizer.task',
        url: 'https://storage.googleapis.com/mediapipe-models/gesture_recognizer/gesture_recognizer/float16/1/gesture_recognizer.task',
        bytes: 8373440,
        sha256: '97952348cf6a6a4915c2ea1496b4b37ebabc50cbbf80571435643c455f2b0482',
        licence: 'the hand landmark models: Apache-2.0 - model card "Hand Tracking (Lite/Full)" (Google, 2021-10): '
            + 'https://storage.googleapis.com/mediapipe-assets/Model%20Card%20Hand%20Tracking%20(Lite_Full)%20with%20Fairness%20Oct%202021.pdf; '
            + 'the gesture embedder + classifier: model card "MediaPipe Hand Gesture Classification" (Google, 2022): '
            + 'https://storage.googleapis.com/mediapipe-assets/gesture_recognizer/model_card_hand_gesture_classification_with_faireness_2022.pdf',
    }),
]);

// <tasks-vision version>-<the models: variant + Google's version number>
export const DIRECTORY = `${TASKS_VISION.version}-pose-lite-1-gesture-1`;

// The files' modification time - fixed, so a rebuilt stage gives the image the same layer
const MTIME = new Date('2026-10-06T00:00:00Z');
const APACHE_LICENSE = join(HERE, '..', '.docker', 'online-detector', 'LICENSE-Apache-2.0.txt');

async function download(url) {
    const response = await fetch(url);

    if (!response.ok) {
        throw new Error(`${url}: HTTP ${response.status}`);
    }

    return Buffer.from(await response.arrayBuffer());
}

/**
 * The regular files of a .tar (ustar; pax headers skipped - npm's names are short) by their path.
 */
export function untar(tar) {
    const files = new Map();
    let offset = 0;

    while (offset + 512 <= tar.length) {
        const header = tar.subarray(offset, offset + 512);

        if (header.every((byte) => byte === 0)) {
            break;
        }

        const field = (start, length) => header.subarray(start, start + length).toString('utf8').replace(/\0.*$/s, '');
        const size = parseInt(field(124, 12).trim() || '0', 8);
        const type = field(156, 1);
        const prefix = field(345, 155);
        const name = prefix === '' ? field(0, 100) : `${prefix}/${field(0, 100)}`;

        if (!Number.isInteger(size) || size < 0 || offset + 512 + size > tar.length) {
            throw new Error(`A broken tar entry at ${offset}`);
        }

        if (type === '0' || type === '') {
            files.set(name, tar.subarray(offset + 512, offset + 512 + size));
        }

        offset += 512 + Math.ceil(size / 512) * 512;
    }

    return files;
}

function sha256(bytes) {
    return createHash('sha256').update(bytes).digest('hex');
}

function licences(written) {
    const lines = [
        'The finish detector of MySpeedPuzzling\'s online competitions - third-party files served as they were published.',
        '',
        `@mediapipe/tasks-vision ${TASKS_VISION.version}`,
        `  from ${TASKS_VISION.tarball} (${TASKS_VISION.integrity})`,
        `  licence: ${TASKS_VISION.licence}`,
        '  privacy notice: https://goo.gle/mediapipe-privacy',
    ];

    for (const path of TASKS_VISION.files) {
        lines.push(`  ${path}  ${written.get(path)}`);
    }

    for (const model of MODELS) {
        lines.push('', model.file, `  from ${model.url}`, `  licence: ${model.licence}`, `  ${model.file}  ${model.sha256}`);
    }

    lines.push('', 'SHA-256 above. The Apache License, Version 2.0 follows.', '', '');

    return lines.join('\n');
}

const brotli = promisify(brotliCompress);
const gzipAsync = promisify(gzip);

async function precompress(path, bytes) {
    // zlib's work runs on libuv's thread pool - the files are compressed in parallel
    const [br, gz] = await Promise.all([
        brotli(bytes, {
            params: {
                [zlib.BROTLI_PARAM_QUALITY]: zlib.BROTLI_MAX_QUALITY,
                [zlib.BROTLI_PARAM_LGWIN]: 24,
                [zlib.BROTLI_PARAM_SIZE_HINT]: bytes.length,
            },
        }),
        gzipAsync(bytes, { level: 9 }),
    ]);

    await put(`${path}.br`, br);
    await put(`${path}.gz`, gz);

    return { br: br.length, gz: gz.length };
}

async function put(path, bytes) {
    await mkdir(dirname(path), { recursive: true, mode: 0o755 });
    await writeFile(path, bytes);
    await chmod(path, 0o644);
    await utimes(path, MTIME, MTIME);
}

async function main(args) {
    if (args.includes('--pins')) {
        process.stdout.write(`${JSON.stringify({ directory: DIRECTORY, tasksVision: TASKS_VISION, models: MODELS }, null, 4)}\n`);

        return;
    }

    const compress = args.includes('--precompress');
    const root = resolve(args.find((arg) => !arg.startsWith('--')) ?? join(HERE, '..', 'public', 'online-detector'));
    const target = join(root, DIRECTORY);
    const staging = `${target}.partial-${process.pid}`;
    const files = new Map();

    const tarball = await download(TASKS_VISION.tarball);
    const integrity = `sha512-${createHash('sha512').update(tarball).digest('base64')}`;

    if (integrity !== TASKS_VISION.integrity) {
        throw new Error(`${TASKS_VISION.tarball}: integrity ${integrity}, expected ${TASKS_VISION.integrity}`);
    }

    const entries = untar(gunzipSync(tarball));

    for (const path of TASKS_VISION.files) {
        const bytes = entries.get(`package/${path}`);

        if (bytes === undefined) {
            throw new Error(`${TASKS_VISION.tarball}: no package/${path}`);
        }

        files.set(path, bytes);
    }

    for (const model of MODELS) {
        const bytes = await download(model.url);
        const digest = sha256(bytes);

        if (bytes.length !== model.bytes || digest !== model.sha256) {
            throw new Error(`${model.url}: ${bytes.length} bytes, SHA-256 ${digest} - expected ${model.bytes} bytes, ${model.sha256}`);
        }

        files.set(model.file, bytes);
    }

    const written = new Map([...files].map(([path, bytes]) => [path, sha256(bytes)]));
    files.set('LICENSES.txt', Buffer.concat([Buffer.from(licences(written)), await readFile(APACHE_LICENSE)]));

    await rm(staging, { recursive: true, force: true });
    const report = [];

    await Promise.all([...files].map(async ([path, bytes]) => {
        await put(join(staging, path), bytes);
        const sizes = compress && path !== 'LICENSES.txt' ? await precompress(join(staging, path), bytes) : null;
        report.push({ path, bytes: bytes.length, sha256: sha256(bytes), ...sizes });
    }));

    for (const directory of [join(staging, 'wasm'), staging]) {
        await chmod(directory, 0o755);
        await utimes(directory, MTIME, MTIME);
    }

    await rm(target, { recursive: true, force: true });
    await mkdir(root, { recursive: true, mode: 0o755 });
    await rename(staging, target);
    await utimes(root, MTIME, MTIME);

    for (const line of report.sort((a, b) => a.path.localeCompare(b.path))) {
        const compressed = line.br === undefined ? '' : `  br ${line.br}  gz ${line.gz}`;
        process.stdout.write(`${line.path}  ${line.bytes}${compressed}  ${line.sha256}\n`);
    }

    process.stdout.write(`\n${target}\nONLINE_DETECTOR_BASE_URL=/online-detector/${DIRECTORY}\n`);
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    main(process.argv.slice(2)).catch((error) => {
        process.stderr.write(`${error?.stack ?? error}\n`);
        process.exit(1);
    });
}
