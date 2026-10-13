#!/usr/bin/env node
// Scores the bench's per-frame signals against the S1 labels with assets/online/finish_machine.js (spikes.md S2).
//
//   node tools/online-bench/score.mjs <signals.json | dir>... [--labels <dir | file>]... [--out <dir>]
//        [--rules '{"holdMs":500}'] [--fps 5] [--view any] [--sweep] [--sweep-signal] [--sweep-views] [--top 15]
//        [--match-ms 5000]
//
// Signals files are what the bench page exports (format msp-online-bench-signals). Labels are S1 label files (.txt);
// a signals file finds its labels by the label's `video:` line, else by file name (<video stem>.txt next to it or in a
// --labels directory). Writes per-video.csv, candidates.csv, summary.md (+ sweep.csv) into --out (default
// tools/online-bench/out/score-<time>/) and prints the summary. The logic is lib/score_core.js (node-tested).

import { mkdir, readdir, readFile, stat, writeFile } from 'node:fs/promises';
import { basename, dirname, extname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { DEFAULT_RULES, resolveRules } from '../../assets/online/finish_machine.js';
import { parseLabels } from './lib/labels.js';
import {
    DEFAULT_GRID,
    gridWithSignal,
    isSignalsFile,
    scoreVideo,
    summaryMarkdown,
    sweep,
    SWEEP_COLUMNS,
    sweepRows,
    toCsv,
    VIDEO_COLUMNS,
    videoRows,
    VIEW_ANGLED,
    VIEW_ANY,
    VIEW_TOP_DOWN,
} from './lib/score_core.js';

const HERE = dirname(fileURLToPath(import.meta.url));

function usage(message = null) {
    if (message) {
        process.stderr.write(`${message}\n\n`);
    }

    process.stderr.write('Usage: node tools/online-bench/score.mjs <signals.json | dir>... [--labels <dir | file>]... [--out <dir>] '
        + "[--rules '{\"holdMs\":500}'] [--fps 5] [--view angled|topDown|any] [--sweep] [--sweep-signal] [--sweep-views] [--top 15] [--match-ms 5000]\n");
    process.exit(message ? 2 : 0);
}

function parseArgs(argv) {
    const args = { inputs: [], labels: [], out: null, rules: {}, fps: null, view: null, sweep: false, sweepSignal: false, sweepViews: false, top: 15, matchMs: 5000 };

    for (let index = 0; index < argv.length; index++) {
        const arg = argv[index];
        const next = () => {
            if (index + 1 >= argv.length) {
                usage(`${arg} needs a value`);
            }

            return argv[++index];
        };

        switch (arg) {
            case '--help':
            case '-h':
                usage();
                break;
            case '--labels':
                args.labels.push(next());
                break;
            case '--out':
                args.out = next();
                break;
            case '--rules':
                try {
                    args.rules = JSON.parse(next());
                } catch (error) {
                    usage(`--rules is no JSON: ${error.message}`);
                }
                break;
            case '--fps':
                args.fps = Number(next());
                break;
            case '--view':
                args.view = next();

                if (![VIEW_ANGLED, VIEW_TOP_DOWN, VIEW_ANY].includes(args.view)) {
                    usage(`--view is ${VIEW_ANGLED}, ${VIEW_TOP_DOWN} or ${VIEW_ANY}`);
                }
                break;
            case '--sweep':
                args.sweep = true;
                break;
            case '--sweep-signal':
                args.sweep = true;
                args.sweepSignal = true;
                break;
            case '--sweep-views':
                args.sweep = true;
                args.sweepViews = true;
                break;
            case '--top':
                args.top = Number(next());
                break;
            case '--match-ms':
                args.matchMs = Number(next());
                break;
            default:
                if (arg.startsWith('--')) {
                    usage(`Unknown option ${arg}`);
                }

                args.inputs.push(arg);
        }
    }

    if (args.inputs.length === 0) {
        usage('No signals files given');
    }

    return args;
}

async function filesIn(path, accept) {
    const info = await stat(path);

    if (!info.isDirectory()) {
        return [resolve(path)];
    }

    const names = (await readdir(path)).sort();

    return names.filter(accept).map((name) => resolve(join(path, name)));
}

const stem = (path) => basename(path).replace(/\.signals\.json$/, '').replace(/\.labels\.txt$/, '').replace(extname(path), '');

async function loadLabels(paths, signalsFiles) {
    const files = new Set();

    for (const path of paths) {
        (await filesIn(path, (name) => name.endsWith('.txt'))).forEach((file) => files.add(file));
    }

    // Next to the signals files too
    for (const signalsFile of signalsFiles) {
        const dir = dirname(signalsFile);

        for (const file of await filesIn(dir, (name) => name.endsWith('.txt'))) {
            files.add(file);
        }
    }

    const labels = [];

    for (const file of files) {
        const parsed = parseLabels(await readFile(file, 'utf8'));
        parsed.warnings.forEach((warning) => process.stderr.write(`${basename(file)}: ${warning}\n`));
        labels.push({ file, stem: stem(file), labels: parsed });
    }

    return labels;
}

function labelsFor(name, signalsFile, labels) {
    const videoStem = name.replace(extname(name), '');

    return labels.find((entry) => entry.labels.video === name)?.labels
        ?? labels.find((entry) => entry.stem === videoStem || entry.stem === stem(signalsFile))?.labels
        ?? null;
}

function timestamp() {
    const now = new Date();
    const pad = (value) => String(value).padStart(2, '0');

    return `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}-${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
}

async function main() {
    const args = parseArgs(process.argv.slice(2));
    const signalsFiles = [];

    for (const input of args.inputs) {
        signalsFiles.push(...await filesIn(input, (name) => name.endsWith('.json')));
    }

    const labels = await loadLabels(args.labels, signalsFiles);
    const videos = [];

    for (const file of signalsFiles) {
        let json;

        try {
            json = JSON.parse(await readFile(file, 'utf8'));
        } catch (error) {
            process.stderr.write(`${basename(file)}: not JSON (${error.message}) - skipped\n`);
            continue;
        }

        if (!isSignalsFile(json)) {
            process.stderr.write(`${basename(file)}: not a bench signals file - skipped\n`);
            continue;
        }

        const name = json.video?.name ?? stem(file);
        const width = json.analysis?.width ?? json.video?.width;
        const height = json.analysis?.height ?? json.video?.height;
        videos.push({
            name,
            file,
            frames: json.frames,
            labels: labelsFor(name, file, labels),
            aspect: width > 0 && height > 0 ? width / height : null,
        });
    }

    if (videos.length === 0) {
        usage('No signals files to score');
    }

    const rules = resolveRules({ ...DEFAULT_RULES, ...args.rules, mode: 'on' });
    const setup = { rules, fps: Number.isFinite(args.fps) && args.fps > 0 ? args.fps : null, view: args.view, matchMs: args.matchMs };
    const results = videos.map((video) => scoreVideo(video, setup));
    let sweepEntries = [];

    if (args.sweep) {
        let grid = { ...DEFAULT_GRID };

        if (args.sweepViews) {
            grid.view = [VIEW_ANGLED, VIEW_TOP_DOWN, VIEW_ANY];
        }

        if (args.sweepSignal) {
            grid = gridWithSignal(grid);
        }

        sweepEntries = sweep(videos, grid, { matchMs: args.matchMs });
    }

    const out = resolve(args.out ?? join(HERE, 'out', `score-${timestamp()}`));
    await mkdir(out, { recursive: true });

    const candidateRows = results.flatMap((result) => [
        ...result.pairs.map((pair) => ({
            video: result.video,
            raiseMs: pair.raise.atMs,
            raiseComplete: pair.raise.complete,
            candidateMs: pair.candidate?.atMs ?? null,
            detectedAtMs: pair.candidate?.detectedAtMs ?? null,
            errorMs: pair.errorMs,
        })),
        ...result.unmatched.map((candidate) => ({
            video: result.video,
            raiseMs: null,
            raiseComplete: null,
            candidateMs: candidate.atMs,
            detectedAtMs: candidate.detectedAtMs,
            errorMs: null,
        })),
    ]);

    const summary = summaryMarkdown({ results, sweepEntries, rules, top: args.top, generatedAt: new Date().toISOString() });
    await writeFile(join(out, 'per-video.csv'), toCsv(videoRows(results), VIDEO_COLUMNS));
    await writeFile(join(out, 'candidates.csv'), toCsv(candidateRows, ['video', 'raiseMs', 'raiseComplete', 'candidateMs', 'detectedAtMs', 'errorMs']));
    await writeFile(join(out, 'summary.md'), summary);

    if (sweepEntries.length > 0) {
        await writeFile(join(out, 'sweep.csv'), toCsv(sweepRows(sweepEntries), SWEEP_COLUMNS));
    }

    process.stdout.write(`${summary}\nWritten to ${out}\n`);
}

main().catch((error) => {
    process.stderr.write(`${error?.stack ?? error}\n`);
    process.exit(1);
});
