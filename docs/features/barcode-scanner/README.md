# Barcode scanner: reliable EAN reading

**Status:** research and proposal 2026-10-05. Step 0 shipped 2026-10-06; Steps 1-4 not built.
**Scope:** `barcode_scanner_controller.js` and everything that reads its output: multiscan, the add-time form, the global
search, plus the server-side EAN lookup (`SearchPuzzle::allByEan()`).

## 1. Why now

On 2026-10-04 the scanner switched to our own zbar decoder on every platform (`47316906`) and started decoding each camera
frame once (`f2a9655a`). The reason was a real misread: Android's built-in detector read Ravensburger `4005555011897` as
`0045555011897`, stored as `45555011897` (`docs/features/multiscan/README.md`, "Decoder").

The first Android player we asked to re-test replied (2026-10-05):

> I just tried scanning the code now and it didn't work - the camera couldn't focus properly, so I can't check how the
> code is read on my device.

Production says the same. Add-form barcode lookups (`/puzzle-by-ean-search/…`, Traefik spans in Tempo, 2026-09-28 to
2026-10-05):

| | before the switch (141 h) | after (27 h) |
|---|---|---|
| Android lookups per day | 169 | 92 (-46 %) |
| iOS lookups per day | 128 | 140 |
| Android share of lookups | 57 % | 40 % |
| Android share of players who scanned | 54 % | 41 % |

27 hours over a weekend is a short window, so read it as a strong signal, not a precise number. Nobody saw it until a
player wrote back, because a scan that fails leaves no trace on the server.

Who scans:
- The add-time form scanner is open to every signed-in player: about 290 successful scans a day from about 750 players a
  week. Of them, 53 % are on Android (47 % Chrome, 6 % Samsung Internet), 44 % on iOS (38 % Safari, 7 % Chrome) and 2 %
  on desktop.
- Multiscan and the global search scanner are members only. Multiscan saw about 115 scans a day from 41 players a week.

So the fix swapped a rare wrong read for no read at all on a big part of Android. This document explains why, and
proposes a scanner that reads blurry, small codes on every phone *and* never hands over a wrong code.

## 2. What happens on an Android phone today

Three things stack up.

**1. Possibly the wrong lens.**
- `facingMode: 'environment'` does not mean "the main camera" in Chrome on Android:
  - the Android device factory lists cameras from the highest index down and appends back cameras in that order;
  - Blink then prefers the first match ([factory source](https://github.com/chromium/chromium/blob/main/media/capture/video/android/video_capture_device_factory_android.cc),
    [tie-break](https://github.com/chromium/chromium/blob/main/third_party/blink/renderer/modules/mediastream/media_stream_constraints_util_video_device.cc)).
- So on phones that expose several back cameras (Samsung especially), the scanner opens the **highest-index** one. That is
  often the ultra-wide, which frequently has no autofocus. A telephoto would not focus closer than about 40-60 cm.
- Field reports: Galaxy S21 opens the ultra-wide ([quagga2 #504](https://github.com/ericblade/quagga2/discussions/504)),
  "always use the last element" ([W3C mediacapture-extensions #20](https://github.com/w3c/mediacapture-extensions/issues/20)).
- Every commercial web SDK re-picks the camera on Android: STRICH, Scandit and Dynamsoft take "camera 0, facing back".
  Chrome 139 renamed the labels from "camera2 N" to "camera N".
- iPhones are fine. Since iOS 16.4, Safari picks the triple/dual-wide virtual camera, which switches to macro by itself.

**2. Few pixels.**
- `getUserMedia({ video: { facingMode: { ideal: 'environment' } } })` asks for no resolution, so Chrome on Android
  delivers **640×480**. Held upright, that is **480 px across**.
- The scanner zooms to 2× where it can. Chrome implements zoom as a crop of the whole sensor (`SCALER_CROP_REGION`),
  scaled down to the stream, so it adds real detail. It does not change the lens's minimum focus distance.

**3. A strict decoder, a focus that is never nudged.**
- zbar decodes the whole frame. It needs **≥ 1.5 px per bar module** on perfectly sharp frames and about 2 with real-world
  blur, and the bars must be sharp: blur up to about 0.45 module (σ). Every read must also pass zbar's `quality > 8` and
  agree over **10 different frames**.
- Chrome runs continuous autofocus over the whole frame and never triggers a new focus pass. `focusMode: 'single-shot'`
  has been a no-op since 2017. What does restart it is a mode change (manual → continuous), which is what STRICH's
  tap-to-focus does.

Pixels per bar module on a typical phone main camera (26 mm equivalent), phone upright, 2× zoom, a puzzle box EAN printed
at 80 % size (module 0.264 mm):

| Stream | across | at 10 cm | at 15 cm | at 20 cm | at 30 cm | zbar's 2 px/module up to |
|---|---|---|---|---|---|---|
| 640×480 (today) | 480 px | 2.5 | 1.7 | 1.3 | 0.8 | **12.7 cm** |
| 1280×720 | 720 px | 5.1 | 3.4 | 2.5 | 1.7 | 25 cm |
| 1920×1080 | 1080 px | 7.6 | 5.1 | 3.8 | 2.5 | 38 cm |

Without zoom support, halve the distances: 640×480 then needs the phone at about 6 cm. A 100 % EAN gives 1.25× more.

Phone main cameras focus from roughly 10-20 cm (Safari reports 0.15 m for the iPhone 13 Pro's main camera). **At
640×480, zbar can only read inside the zone where the camera cannot focus.** The player moves closer to make the code
bigger, the picture goes soft, and nothing reads. On the wrong lens it is worse. The preview looks soft anyway: 480 px
stretched over a phone screen 1,100+ device pixels wide.

Google's detector got away with it because it reads coarser and blurrier frames than zbar. That is also the decoder
that produced the misread.

We do not know which phone, lens or resolution the reporter's scanner actually opened. The beacon in §5.1 will log it.

## 3. Findings (synthetic frames)

A simulator renders camera frames of EAN-13 codes with a controlled sampling (px per module) and blur, plus noise, low
contrast, tilt, perspective, bars printed wider or thinner, glare, phone-style sharpening and gamma. Every decoder sees
the very same frames. The method and scripts are in §9.

### 3.1 Blur is the wall, and it sits in a different place for each decoder

Share of single frames read correctly. Gaussian blur σ in bar modules, sampling 2-3 px/module:

| Blur σ (modules) | 0.3 | 0.45 | 0.6 | 0.75 | 0.9 | 1.05 |
|---|---|---|---|---|---|---|
| zbar, `quality > 8` (today) | 100 % | 77-87 % | 7-10 % | 0 % | 0 % | 0 % |
| zxing-cpp (`zxing-wasm` 3.1.4) | 90-100 % | 73-80 % | 3-30 % | 0 % | 0 % | 0 % |
| Model-based prototype (§5.4) | 100 % | 100 % | 100 % | 100 % | 100 % | 100 % |

Defocus (a disk, the shape real out-of-focus blur has), 2-3 px/module:

| Disk radius (modules) | 0.5 | 0.7 | 0.9 | 1.1 | 1.3 | 1.5 |
|---|---|---|---|---|---|---|
| zbar, `quality > 8` | 100 % | 87-100 % | 3-10 % | 0 % | 0 % | 0 % |
| zxing-cpp | 70-97 % | 73-93 % | 47-63 % | 0 % | 0 % | 0 % |
| Model-based prototype | 100 % | 100 % | 100 % | 100 % | 100 % | 100 % |

Sampling: zbar needs ≥ 1.5 px/module even on sharp frames (1.25 → ~50 %). The prototype read 100 % at 1.25 px/module.

Mixed "hard" frames (random blur of both kinds, motion blur, noise up to 18 levels, contrast down to 25 %, tilt ±8°,
perspective, uneven bars, gamma, sharpening halos), 150 frames:

| | read correctly | wrong |
|---|---|---|
| zbar, `quality > 8` | 45 | 0 |
| zxing-cpp | 62 | 0 |
| Model-based prototype | 148 | 2, both below the acceptance threshold |

### 3.2 Misreads: where they come from

- The Android misread is a *parity* misread: every G-coded digit of the left half comes back as the L-coded digit one bar
  module away. The parity pattern becomes LLLLLL, so the first digit becomes `0`, and for some prefixes the check digit
  still holds.
- All known check-digit-valid misreads are reproduced by a uniform bar width change of at most one module: our two, plus
  three reported against ZXing. The bars come out thinner or thicker than nominal from printing, exposure or blur.
- Decoders that classify each digit by its bar and space widths (the ZXing family) fall into it. zxing-cpp made exactly
  this misread in the simulation: `0065577011897` for `4005555011897`, `0065530100330` for `4005556100330`,
  `0045517011897`, …
- Google's Android engine is not ZXing:
  - Chrome's `BarcodeDetector` wraps Play services' "Barhopper";
  - ML Kit's build of it ships TFLite models for 1D codes (a feature extractor plus an autoregressive decoder), so it is at
    least partly a neural sequence decoder;
  - that explains both its blur tolerance and its stable, check-digit-valid misreads;
  - its public misreads go beyond the parity kind (EAN-13 `9100000013510` read as `2455340606005`; a blurry EAN-13 read as
    an EAN-8). **A read from the native detector alone can never be trusted, whatever its first digit.**
- zbar measures edge-to-similar-edge distances. UPC was designed for exactly that, because they do not change when all
  bars grow or shrink. zbar never made the parity misread here.
- zbar is not flawless: in Dynamsoft's benchmark on out-of-focus EAN-13 it made 8 misreads (§3.5). Its remaining risk is
  two bar-width-tested digit errors (1↔7, 2↔8) that cancel in the check digit. They are random, not stable, so a few
  agreeing frames filter them.
- The model-based prototype fits the bar width change as a parameter, so it does not make it either. On 243 frames of the
  three exposed codes, with bars changed by -0.6 to +0.6 module plus blur: prototype 240 right, 0 wrong; zbar 74 right,
  0 wrong; zxing 77 right, **5 wrong, all of them this misread**.
- Wrong reads cluster on codes starting with `0`, the UPC-A space. Most of zxing's wrong reads in every run started with
  `0`, and zbar's rare garbage reads after aggressive sharpening did too. But **26.5 % of catalogue codes genuinely start
  with `0`** (Buffalo Games, Ceaco, Masterpieces, Cobble Hill, Eurographics, …). So `0` codes need more evidence, they
  cannot simply be refused.
- Exposure is wide: **51.6 % of catalogue codes** have a same-direction parity misread that keeps the check digit. It is
  not a Ravensburger quirk; Ravensburger is simply the most scanned brand.

### 3.3 The catalogue is an exact safety net

When a scanned code is not found, the codes it could have been misread from can be computed (the inverse of the parity
misread) and looked up:

- Across the catalogue (dump of 2026-10-04), **every one of the 57,176 possible misread codes resolves to exactly one
  catalogue code**, the right one. Nothing is ambiguous.
- No genuine code can be "corrected" by mistake: the correction only runs when the code is not found. Only 2 genuine-looking
  catalogue codes collide, and both are stored misreads.
- Catalogue codes that are still misreads:
  - `45555014409`, `45555018124`, `45555020493` (already in `docs/TODO.md`).
  - **New:** `045570100330` on *The World of Trolls* (`01947b9c-24c6-7359-972a-54f6a6d86402`). This is most likely
    Ravensburger `4005556100330`, read with a mixed-direction parity misread. Worth a look at the box.

### 3.4 Speed

zbar on an M-series Mac (phones are roughly 3-6× slower):

| Frame | every line both ways | every 2nd line both ways |
|---|---|---|
| 480×640 full frame (today) | 11.6 ms | 6.5 ms |
| 1080×1920 full frame | 71 ms | 36 ms |
| 1080×640 centre band of 1080p | 24 ms | **11.5 ms** |

Decoding the band the viewfinder shows, at full resolution, costs about what today's 640×480 frame costs.

The prototype decoder is unoptimised: about 65 ms per scanline band on a Mac, 70 % of it in a list-Viterbi written with
string keys and objects. The model maths itself is about 5 % of the time. An implementation with typed arrays, and
templates built by adding precomputed edge responses, should be well over 10× faster. It still has to be measured on a
mid-range Android.

### 3.5 What others found

- **Gallo & Manduchi** ([TPAMI 2011](https://users.soe.ucsc.edu/~manduchi/papers/barcodes.pdf)) decoded UPC-A with
  deformable templates and dynamic programming, the same family as our prototype:
  - it reads scanlines down to about 1.05 px/module;
  - on their "no focus" set it read 9 of 21 codes, where two commercial decoders of the time read none.
  - No open-source implementation of that decoder exists, and no open-source JS/WASM decoder beats zbar or zxing on blurry
    EAN. `zedbar` (Rust/WASM, 2026) is a port of zbar with the same decoder.
- **Dynamsoft's benchmark** on out-of-focus EAN-13 (ArTe-Lab set 2, vendor-run, versions not disclosed):

  | Engine | Read rate | Misreads |
  |---|---|---|
  | Dynamsoft | 81.9 % | 0 |
  | Scandit | 79.1 % | 1 |
  | zbar | 14.0 % | 8 |
  | zxing-cpp | 10.2 % | 2 |

  Same picture as our simulation: free decoders hit a blur wall early, and the paid ones use model-based or neural
  deblurring.
- **Reference false-read rate:** UPC-A by laser scanners with reference decoders, about 1 error in 400-800k characters
  (Ohio University / AIM data integrity test). A mod-10 check digit lets about 10 % of random multi-digit errors through.
- **Upstream activity:** zbar's EAN decoder has not changed since 2019 and `@undecaf/zbar-wasm` is dormant (0.11.0, 2024,
  still zbar 0.23.90), so upgrading changes packaging, not reading. zxing-cpp is active (3.1.1, 2026-07) but has nothing
  for the parity misread.

## 4. Requirements

1. Read within about a second at a comfortable 15-30 cm, on Android and iOS, in normal indoor light.
2. Never hand over a wrong code. Target: no check-digit-valid misread in 100,000 accepted scans, and no "code" read off
   box art.
3. The same decoding path on every platform, so a bug or fix shows on Jan's iPhone too.
4. Tell the player what to do when it cannot read ("move further away", "turn on the light"), instead of silently failing.
5. Measured, so the next regression shows in the numbers before players write in.

## 5. Proposal: one step at a time, each one measured

Not everything at once:
- **The failures are per device.** Lens choice, focus behaviour and supported resolutions differ by phone model and
  browser. We can test four phones; the 11,000+ players' phones test it for us, but only if we measure.
- **2026-10-04 was a one-shot change** verified on one iPhone and in simulation. It cost a large share of Android scans,
  and nobody saw it for a day.
- **One variable per release.** With five changes in one release, a drop on Samsung Internet cannot be traced to any of
  them, and undoing it means undoing the good ones too.
- **Wrong reads are too rare to count.** At our volume (~290 add-form + ~115 multiscan scans a day), a 1-in-10,000
  misread shows up about once a month. Misread safety is therefore verified by construction, not by an A/B test:
  - the synthetic bench (§7);
  - cross-engine agreement in shadow mode (§5.4);
  - the server net (§5.1).

Development can overlap: Step 3 can be built while Step 2 is measured. The gates are on rollout, not on coding.

### 5.0 Step 0, now: stop the loss on Android (shipped 2026-10-06)

- Restore the engine choice from before `47316906`:
  - the browser's `BarcodeDetector` where it supports `ean_13` (Android Chrome, Samsung Internet);
  - zbar everywhere else.
  - Keep `f2a9655a` (one decode per frame, frames with two codes ignored, loop guard).
- The misread stays contained meanwhile:
  - `Value\EanList` already refuses the `45555…` forms on the add form and in "Suggest a change";
  - §5.1 widens the net within days.
- The trade-off: the parity misread (16 stored cases in ~2.5 years of native scanning) against a large share of Android
  scans failing now.
- As built (`_detectorClass()` + `_nativeReadsEan()`):
  - the native detector is used when `getSupportedFormats()` lists `ean_13`;
  - zbar is downloaded only when it is needed, so Android no longer depends on jsdelivr;
  - checked with mocked browsers (every branch, incl. the CDN down) and with Chrome's fake camera on the zbar path
    (Ravensburger code read in 490 ms). Chrome on Linux does not expose `BarcodeDetector` at all.
- **Check:** re-run the Tempo count (§1) after a few days. Android lookups per day should be back near 169.

### 5.1 Step 1, days 1-3: measure, and the server net. Nothing changes in how the scanner reads

**One beacon per scanner session**, `POST /-/scan-session`, like `/-/asset-load-failure`. Logged at `info` on its own
channel, kept out of Sentry. Fields:
- page (multiscan / add form / search) and platform group;
- the phone model where the browser tells it (`navigator.userAgentData.getHighEntropyValues(['model'])`, Chromium only;
  user agents no longer carry it);
- camera label, video size, zoom, `getSettings()` and `getCapabilities()` (focus modes, torch, `focusDistance.min`);
- engine(s) that produced the accepted code, time to accept, frames decoded;
- outcome: accepted / closed without a read / typed by hand;
- the experiment variant, and whether the server corrected the code.

**`?scan-debug=1` overlay** with the same live data, plus per-frame reads. Testers and players can send a screenshot
instead of "it doesn't focus".

**Server net:**
- `SearchPuzzle::allByEan()`, when nothing is found and the code starts with `0`: compute the codes it could have been
  misread from (inverse parity misread, any direction) and look them up.
  - Exactly one found → return it, marked as corrected, and log it.
  - It is one chokepoint: multiscan (`GetMultiscanCandidates`), the add form (`SearchPuzzleByEanController`) and API v1.
- `Value\EanList`: generalise the `45555…` rule. Refuse a code whose 7-digit prefix is a misread image of a well-used
  catalogue prefix, when that prefix is not itself a real one, and suggest the original. `LinkEanToPuzzle` and quick-add
  use the same rule.
- File change proposals for the stored misreads (§3.3), as Jan's rule says.

**Gate:** 3-5 days of baseline per platform group (Android Chrome, Samsung Internet, iOS Safari, iOS Chrome):
- sessions opened;
- share that read a code;
- median time to read;
- the top phone models of sessions that never read.

### 5.2 Step 2: camera fixes (about 1 day to build), A/B

Small changes in `barcode_scanner_controller.js` that help any decoder:

- **The main lens on Android:**
  - after the first `getUserMedia` (labels need permission), enumerate the `videoinput` devices and prefer the back camera
    labelled `/camera2? 0, facing back/`;
  - check that its capabilities have `'continuous'` in `focusMode` (a torch and a small `focusDistance.min` confirm the
    main camera), otherwise try the next back camera;
  - reopen with `deviceId: { exact }` when it differs from what `facingMode` gave, and remember the choice in
    `localStorage` (deviceId, with the label as fallback);
  - iOS keeps `facingMode`, because Safari already picks the right virtual camera.
- **More pixels:** ask in landscape numbers, `width: { ideal: 1280 }, height: { ideal: 720 }` plus `resizeMode: 'none'`
  (a native camera format, not a scaled one). Read the real size from `videoWidth`/`videoHeight`, never from
  `getSettings()`, which can report the unrotated size on iOS. 720p already doubles px/module: 1.5× the pixels across,
  and a 16:9 stream held upright sees a narrower strip (table in §2).
- **Keep the speed:** a whole 720p frame costs zbar about 3× today's 640×480. So hand `detect()` a canvas with the centre
  band of the video instead of the `<video>`.
- **Focus:**
  - `focusMode: 'continuous'` plus a centre `pointsOfInterest`, re-applied after each zoom change (zoom clears it);
  - a "kick" when the player taps the viewfinder or after ~2 s without a read: manual at ~0.25 m (clamped to the
    camera's range) → 250 ms → continuous. A mode change restarts Chrome's autofocus; `single-shot` does nothing.
- **Torch** button where `getCapabilities().torch` exists (Chrome Android 59+, Safari about 17.4+).

**Gate:**
- 50/50 by session for about 5 days. Keep it when the read rate is better on Android and not worse on any group.
- Ask Vanja to scan her Ravensburger box with `?scan-debug=1`.
- If a group gets worse, the beacon's camera labels and models show which phones; fix and repeat.

### 5.3 Step 3: frame pipeline (2-4 days to build), A/B

- **Resolution:** `width: { ideal: 1920 }, height: { ideal: 1080 }` once decoding is cropped, falling back to what the
  phone gives. At 2× zoom that is ≥ 2 px/module up to about 38 cm, comfortably inside every camera's focus range (table
  in §2).
- **Decode what the player sees:**
  - grab only a centred band at full resolution, about 90 % of the width × 25-40 % of the height (STRICH's advice for 1D
    codes). Use `VideoFrame.copyTo()` of the Y plane, which is 8-bit grey already and exactly what zbar takes, or
    `createImageBitmap(video, sx, sy, sw, sh)`;
  - rows and columns alternate per frame, so a box held sideways still reads (Jan's 2026-10-04 call).
- **Web Worker:**
  - decoding off the main thread, paced by `requestVideoFrameCallback`, one frame in flight (the newest frame wins);
  - zbar called directly (`@undecaf/zbar-wasm`, inlined build bundled through npm so the service worker caches it);
  - nothing fetched from jsdelivr at run time any more, and `@undecaf/barcode-detector-polyfill` goes away;
  - a canvas fallback for browsers without `VideoFrame`/OffscreenCanvas.
- **Zoom:** keep about 2×, derived from `focusDistance.min` where the browser gives it (Apple's WWDC21 approach: zoom so
  a typical code fills the frame at the closest distance the lens can focus), capped at 2.5×. On Chrome it is real sensor
  detail; at 1080p, more than ~2× adds none.
- **Guide** (texts in all 6 locales, handed to Stimulus through data attributes):
  - an EAN-shaped frame in the viewfinder and one line: "Hold the box about 20 cm away";
  - live hints once Step 4 provides px/module and blur estimates: "Move further away", "Move closer".
- **Acceptance:**
  - zbar reads need 4 agreeing frames with `quality ≥ 3` instead of 10 with `quality > 8`, and only if no other code was
    read in the last ~2 s. zbar's wrong reads are rare and change from frame to frame; STRICH accepts an EAN after 2
    identical reads within 350 ms;
  - codes starting with `0`, and EAN-8 (store codes like Innovakids `2…`), keep a higher bar.

**Gate:** as Step 2, plus decode time per frame on the slowest phones in the beacon.

### 5.4 Step 4: our own blur-tolerant decoder (1-2 weeks to build), shadow first

Productionise the prototype from §3 as a second engine next to zbar, in the same worker. It is the Gallo & Manduchi
family (§3.5) with two additions: the bar width change is a fitted parameter, and evidence adds up over frames. Paid SDKs
get their blur tolerance the same way, through model-based or neural decoding.

- **How it works:** averaged scanline bands → locate the code → fit geometry, blur and bar width change → decode all 12
  digits jointly with the parity pattern and the check digit (list Viterbi) → rescore the best candidates with the exact
  blurred model. Output: best code, its log-likelihood margin over the runner-up (normalised by the fit's own residual),
  and the estimated px/module and blur.
- **Why it is safe:** wrong decodes and non-codes have tiny margins. In the stress set, real reads scored 58 or more
  (median 2,627). Every wrong decode and every negative frame scored 47 or less: 750 frames of codes 30 % out of frame,
  random stripes, two codes side by side. Margins add up over frames, so the evidence for a real code grows with every
  frame and a fluke does not.
- **Work left:**
  - typed-array implementation and speed on a real Android;
  - EAN-8;
  - localisation in busy box art and with two codes in view;
  - vertical orientation;
  - calibration on real frames (§7).
- **Fusion with zbar:** a code is accepted when evidence from either engine crosses its threshold, from at least 2 frames,
  and no other code competes. Agreement of both engines is the fast path. A `0`… code or an EAN-8 needs both engines, or
  twice the margin.

**Gates:**
1. **Shadow, 1-2 weeks.** It runs on real sessions and logs what it would have read (code, margin, time to read), but
   never hands anything over. It passes when:
   - it never confidently disagrees with a code another engine accepted;
   - its margins on real frames separate as they did in simulation;
   - we know how many failed sessions it would have saved.
2. **Live for 50 % of sessions:** read rate and time to read.
3. **On Android, own decoder vs Google's detector:** Google's detector goes when ours is at least as good. Then every
   platform runs the same path.

### 5.5 Rules for every step

- **A switch per step:** a server-side setting rendered into the page, so a bad step is undone without a code change.
  `?scanner=…` forces a variant for support cases.
- **A/B by scanner session,** random, recorded in the beacon.
- **Pass:**
  - the read rate is not worse on any platform group and better where intended;
  - roll back if any group drops by more than ~5 points.
- **Volume:** ~170 Android sessions a day split 50/50 gives ~400 per arm in 5 days, enough to see a 10-point change in the
  read rate. A drop like 2026-10-04 shows within hours.
- **Device coverage:** the beacon lists the models of sessions that never read. They become the test list for the next
  step.

## 6. Open questions for Jan

1. OK to restore Google's detector on Android right away (§5.0), until our own decoder replaces it (§5.4)?
2. Server correction: return the corrected puzzle silently (proposed), or ask "Did you mean …?" first?
3. Own decoder: JavaScript in a worker first (proposed), or straight to Rust/WASM?
4. Real frames for calibration (§7): OK to add an opt-in "send these frames" button in debug mode?

## 7. Testing

- **Synthetic bench:** move the simulator into the repo (`tests/barcode/`, node). It is the decoders' regression test:
  read rate per blur/px cell must not drop, and zero accepted wrong reads on the exposed codes (`4005555…`, `4005556…`,
  `3770039…`) and on the negative frames.
- **Fake camera e2e:** the Selenium + `.y4m` harness from 2026-10-04 checks the pipeline wiring (frames, worker,
  acceptance, stop/start).
- **Real devices:**
  - Phones: Jan's iPhone, a Samsung A-series, a Pixel, and Vanja's phone.
  - Boxes: Ravensburger `4005555`/`4005556`, Blitz `3770039`, a US UPC box (Buffalo Games or Ceaco), Trefl, Clementoni,
    Schmidt, one glossy box, one small or truncated code.
  - Conditions: 10 / 15 / 20 / 30 cm, normal and dim light.
  - Record time to read and the debug overlay.
- **Real-frame corpus:** in debug mode, an opt-in button sends the last ~20 frames of a failed session. That gives us real
  Android blur to calibrate the thresholds on, not only synthetic frames.

## 8. What is deliberately not proposed

- **zxing-wasm:** it reads somewhat more than zbar, but makes exactly the misread we are fighting (§3.2).
- **A paid SDK.** Dynamsoft and Scandit read about 80 % of out-of-focus EAN-13 in Dynamsoft's benchmark (§3.5). Cost
  (2026):

  | SDK | Price | Notes |
  |---|---|---|
  | STRICH | €99/month up to 10k scans; €249/month up to 100k | Licence check reports scan counts |
  | Dynamsoft | from $1,499/year | |
  | Scanbot | from $2,500/year, unlimited scans | Fully offline |
  | Scandit | quote only, annual | Licence tied to the domain |

  Even Scandit was still fixing EAN false positives in 2026, so our misread gate (agreement + server net) would stay
  anyway. Not needed if Step 4 holds up on real frames; it stays the fallback if it does not.
- **zbar upgrade or `zedbar`:** same EAN decoder, same blur wall.
- **Refusing every code that starts with `0`:** that is a quarter of the catalogue.

## 9. Method and numbers

- **Simulator:** renders an EAN-13 into a grey frame with a sub-pixel 1D profile, then applies:
  - Gaussian, disk (defocus) and box (motion) blur;
  - bar width change and per-edge jitter;
  - tilt and perspective;
  - illumination gradient, glare, gamma and unsharp-mask sharpening;
  - sensor noise.
- **Decoders compared on identical frames:**
  - `@undecaf/zbar-wasm` 0.9.15 (what production loads) and 0.11.0, which behaves the same;
  - zxing-cpp through `zxing-wasm` 3.1.4;
  - the model-based prototype.
- **Catalogue numbers:** 21,739 valid EAN-13/UPC-A puzzle codes (dump of 2026-10-04); 83 eight-digit codes in the dev
  database, 59 of them valid EAN-8.
- Scripts, raw results and the code dump are local to Jan's Mac, not in git: `.claude/worktrees/barcode-research/`. Its
  README lists the commands (`grid.mjs`, `mlegrid.mjs`, `stress.mjs`, `misreadcase.mjs`, `inverse_any.mjs`,
  `speed.mjs`), and `mle.mjs` is the decoder prototype. They move into `tests/barcode/` with Step 4.
