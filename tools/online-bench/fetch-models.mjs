#!/usr/bin/env node
// Downloads the pinned detector assets (assets/online/hands_up_signal.js DETECTOR_ASSETS) into
// tools/online-bench/models/ (gitignored) - for a bench run without the CDNs, or to keep exactly these bytes.
//
//   node tools/online-bench/fetch-models.mjs
//
// Then open the bench with ?assets=local. The models are checked against the MD5 Google's bucket lists for them.

import { createHash } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { DETECTOR_ASSETS } from '../../assets/online/hands_up_signal.js';

const HERE = dirname(fileURLToPath(import.meta.url));
const MODELS = join(HERE, 'models');

// The bucket's md5Hash of each pinned model (storage.googleapis.com/storage/v1/b/mediapipe-models/o?prefix=…)
export const MODEL_MD5 = {
    pose_landmarker_lite: 'BKdd33yBGsehpFIyZt19iA==',
    gesture_recognizer: 'Tb9IXEcyB2URAKGJU0PzWg==',
};

// Saved as .js: every static server sends that as JavaScript (a module import needs it)
export const LOCAL_FILES = [
    { url: DETECTOR_ASSETS.tasksVisionUrl, path: 'tasks-vision/vision_bundle.js' },
    { url: `${DETECTOR_ASSETS.wasmBaseUrl}/vision_wasm_module_internal.js`, path: 'tasks-vision/wasm/vision_wasm_module_internal.js' },
    { url: `${DETECTOR_ASSETS.wasmBaseUrl}/vision_wasm_module_internal.wasm`, path: 'tasks-vision/wasm/vision_wasm_module_internal.wasm' },
    { url: DETECTOR_ASSETS.poseModelUrl, path: 'pose_landmarker_lite.task', md5: MODEL_MD5.pose_landmarker_lite },
    { url: DETECTOR_ASSETS.gestureModelUrl, path: 'gesture_recognizer.task', md5: MODEL_MD5.gesture_recognizer },
];

async function main() {
    for (const file of LOCAL_FILES) {
        const response = await fetch(file.url);

        if (!response.ok) {
            throw new Error(`${file.url}: HTTP ${response.status}`);
        }

        const bytes = Buffer.from(await response.arrayBuffer());

        if (file.md5) {
            const md5 = createHash('md5').update(bytes).digest('base64');

            if (md5 !== file.md5) {
                throw new Error(`${file.url}: MD5 ${md5}, expected ${file.md5}`);
            }
        }

        const target = join(MODELS, file.path);
        await mkdir(dirname(target), { recursive: true });
        await writeFile(target, bytes);
        process.stdout.write(`${file.path}  ${(bytes.length / 1e6).toFixed(1)} MB\n`);
    }

    process.stdout.write('\nOpen the bench with ?assets=local\n');
}

main().catch((error) => {
    process.stderr.write(`${error?.stack ?? error}\n`);
    process.exit(1);
});
