# Detection bench (`tools/online-bench`)

Spike S2 of the online competitions ([`docs/features/online-competitions/spikes.md`](../../docs/features/online-competitions/spikes.md)):
the tools that score the hands-up detection and the "no missing pieces" check on recorded solves (S1 footage) -
**before** Phase 2 builds on them. "Autodetection and reliability is the key of success" (Jan): every number in the
design that is still a guess (hold, thresholds, frame rate, which model) gets decided here, on footage.

| Piece | What it does |
|---|---|
| `index.html` + `bench.js` + `lib/` | a local page: steps a video frame by frame through **the** detector worker the phones will run (`assets/online/detector.worker.js`), draws the landmarks, runs the finish machine (`assets/online/finish_machine.js`), exports per-frame signals; a labelling mode writes the S1 label file |
| `score.mjs` | node: signals + labels → the finish machine → detection error, misses, false raises, candidates per video; a parameter sweep → a ranked table; CSV + markdown |
| `completeness/` | Python 3 + OpenCV: background holes (empty-mat photo vs finish photo) and reference-grid matching → `complete` / `gaps` (boxes) / `unsure`; a synthetic self-test with confusion matrices (the checks: `tools/online-cv/completeness.py`, shared with the CV worker) |
| `fetch-models.mjs` | downloads the pinned MediaPipe assets into `models/` for offline runs |

**Code only.** Footage shows people and homes: it never enters this public repository. `footage/`, `out/` and
`models/` are gitignored; keep the videos in the private folder Jan names (S1) and point the tools there.

## The bench page

Serve the **repository root** with any static server - the page imports `/assets/online/*.js` directly, no build:

```sh
python3 -m http.server 8765        # from the repository root
open http://localhost:8765/tools/online-bench/
```

Drop a video and its label file (`.txt`) anywhere on the page, or choose them. To open footage by URL (scripted
runs, `?video=/footage/07.mp4&labels=/footage/07.txt`), make `tools/online-bench/footage` a symlink to the footage
folder (gitignored) or let the static server map `/footage/` to it.

- **Detector:** view `angled` (pose), `topDown` (gestures) or `any` (both - the signal of either; the bench default,
  so the score can compare); analyse at 2-30 fps (10 by default: `score.mjs` thins it to what a phone does);
  frames sent 640 px wide like the phone (or larger); "Mirrored" flips the frames as a front camera shows them.
- **Models:** pinned in `assets/online/hands_up_signal.js` (`DETECTOR_ASSETS`): `@mediapipe/tasks-vision@1.1.0` from
  jsDelivr, `pose_landmarker_lite` and `gesture_recognizer` from Google's bucket at the numbered path `/float16/1/`.
  Offline: `node tools/online-bench/fetch-models.mjs` (checks the MD5s), then open the page with `?assets=local`.
- **Needs WebGL2** - MediaPipe uploads every frame through it, also on the CPU delegate. A desktop Chrome / Edge /
  Safari 17+ has it; headless Chrome in a container needs `--enable-unsafe-swiftshader` (software GL). Without it the
  worker refuses to start (`No WebGL2 in this worker`) - the same answer a phone without WebGL2 in workers gets.
- **What you see:** the skeleton (wrists pink), the shoulder line both wrists must clear (dashed), the frame's
  signal; the timeline: one track per signal (green = raised, darker = higher score; grey = down; red = nobody / no
  hands), the machine's candidates (line at the moment, triangle when it was known), short raises (orange) and raises
  held back by the cooldown (violet), your labels (dashed: blue complete, orange incomplete), the playhead. Click the
  timeline to jump. The finish rules can be changed live - the candidates and the score vs your labels follow.
- **Export signals JSON** (`<video>.signals.json`): format `msp-online-bench-signals` v1 - the video, the analysis
  (fps, view, frame size), the detector (URLs, user agent, load time), inference times, and per frame `t` (the frame's
  own time, ms), `inferenceMs`, the compact landmarks (`pose`, `hands`) and the signals (`signal`, `angled`,
  `topDown`). Keep it next to the footage, not in the repository.

### Labelling mode

Keys (not while typing in a field):

| Key | |
|---|---|
| `←` / `→` | one video frame (the "Video frame rate" field) |
| `Shift` + `←` / `→` | one second; `Alt` + arrows: 0.1 s |
| `Space` | play / pause |
| `M` | empty mat - first press its start, second its end |
| `L` | last piece placed |
| `H` | hands up - a **complete** finish |
| `I` | hands up - **incomplete** (a premature raise, a piece still missing) |
| `E` | hands down: the end of the latest raise before the playhead |
| `Backspace` / `Delete` | the mark nearest to the playhead (within 1 s) |
| `S` | save the label file |

Mark a raise at the **first frame where both wrists are above the shoulders** - the moment the review would confirm.
The label file is the S1 format; several raises use its list form (below) and moments are written to the
millisecond of the frame.

### The label file - the list form

S1 has one `hands_up`. A video with premature raises needs several, so `hands_up` may appear once per raise, each a
time or a range `start-end` (when the hands went down), optionally followed by `complete` or `incomplete`. A raise
without that word is incomplete unless it is the last one, which takes the file's `complete:` - so the one-raise S1
file reads exactly as before.

```
video: 12.mp4
hands_up: 00:10:05.0-00:10:06.1 incomplete   # a piece was still missing
hands_up: 00:12:42.2 complete
```

## score.mjs

```sh
node tools/online-bench/score.mjs ~/msp-footage/signals/ --labels ~/msp-footage/labels/ --sweep --out ~/msp-footage/score
```

- Inputs: signals files (or folders of them); label files are found by their `video:` line or by name (`07.txt`
  next to `07.signals.json`, or in a `--labels` folder).
- `--rules '{"holdMs":700}'` scores other finish rules; `--fps 5` thins the frames to a phone's rate; `--view angled`
  recomputes the signal of one view from the stored landmarks. The machine arms like the phones' (stream P2F: a raise
  already going on at a video's first frame or after a gap is never a candidate) - `--rules '{"armMs":0,"armFrames":0}'`
  scores the machine without arming; `armMs` / `armFrames` are sweepable grid keys too.
- `--sweep`: every combination of hold (300-1000 ms), minScore, tolerated dropped frames, max gap and frame rate
  (all / 5 / 4 / 2 fps); `--sweep-views` adds the three views; `--sweep-signal` adds the signal's own thresholds
  (wrist visibility, lift, gesture score), recomputed from the landmarks.
- Outputs (default `tools/online-bench/out/score-<time>/`, gitignored): `per-video.csv`, `candidates.csv`
  (every labelled raise with its candidate and every candidate without a raise), `sweep.csv`, `summary.md` (printed
  too).

What the numbers mean:

| Number | |
|---|---|
| **error** | candidate moment − labelled raise (ms, + = late) - the review's "confirmed − detected" with the label as the confirmed moment |
| **detected** | a candidate within ±5 s of the labelled raise (the finish burst around a candidate covers that much, so a reviewer can still pin the moment) |
| **within 1 s** | README §1 P3's target |
| **missed** | a labelled raise without a candidate; *in a cooldown* = the machine saw it but held it back (a raise within 10 s of an earlier candidate - by design, the button covers it) |
| **false raises** | candidates that match no labelled raise |
| **finish** | the last complete raise - a missed finish costs a person |
| **wait** | detectedAtMs − labelled raise: how long the participant waits for "Finish recorded" |

The sweep ranks rows by missed finishes, then missed raises, then false raises, then the 90th percentile of the
absolute error, then its median; among equals the row closest to today's defaults first (a change needs footage that
asks for it). Matching takes the closest (raise, candidate) pairs first.

## completeness/

Python 3.12 + OpenCV 4.14 + numpy (pinned once, in `tools/online-cv/requirements.txt` - this folder's
`requirements.txt` includes it), run in a throwaway container so nothing is installed on the machine. The checks
themselves live with the CV worker - `tools/online-cv/completeness.py`, the one implementation for the bench and the
app (README §5.8) - so the container mounts `tools/`:

```sh
docker run --rm -v "$PWD/tools":/tools:ro -w /tools/online-bench/completeness python:3.12-slim \
  sh -c 'pip install -q -r requirements.txt && python selftest.py'
```

- `selftest.py [--per-cell 6] [--others 2] [--seed 7] [--out DIR]` builds synthetic finish photos - a procedural
  motif (`texture`, `smooth`, `low_texture` sky) cut into 300 / 500 / 1000 pieces, 0-2 pieces dropped to the mat
  (lying loose next to it), photographed at an angle with the phone bumped between the empty-mat photo and the finish,
  light drift, noise, blur and JPEG - on a contrasting mat, on a table close to the motif's colours, or with an arm
  over the puzzle - and prints the confusion matrix of each approach per condition and motif. **The number that
  matters is a false "complete"** (a missing piece passed); a false "gaps" or an "unsure" costs a reviewer a look.
- `check.py --finish F --pieces N [--mat M] [--reference R] [--grid 25x40] [--draw out.jpg]` runs the checks on real
  photos (the empty mat → background holes, the motif → grid matching, both → combined) and prints the verdicts as
  JSON; `--draw` marks the outline and the gaps.
- `tools/online-cv/completeness.py` is the library: `check_holes()`, `check_grid()`, `combine()` - every threshold a
  named option with its reason - and `check_photo()`, what the worker answers with. Every doubt answers `unsure`,
  never `complete`. A change of a threshold or a rule bumps its `VERSION`.
- The same self-test through the worker's HTTP endpoint (+ unfinished puzzles): `tools/online-cv/http_selftest.py`
  (its README).

## Tests

The pure parts are pinned by node harnesses run from PHPUnit like the rest of `assets/online/`:
`tests/online-finish-machine-harness.mjs` (`OnlineFinishMachineScriptTest`), `tests/online-hands-up-harness.mjs`
(`OnlineHandsUpScriptTest` - signal, pacing, the worker's protocol against a fake MediaPipe) and
`tests/online-bench-harness.mjs` (`OnlineBenchScriptTest` - labels, scoring, sweep).
