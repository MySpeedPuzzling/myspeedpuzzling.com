# Participant page - real-device protocol

The camera, the wake lock, sounds and the browsers' background rules cannot be tested in CI (README §5.5 "Browser
realities"). Run this on real phones before every test cup and after any change to `assets/controllers/compete_controller.js`
or `assets/online/{camera,wake_lock,beeps,frame_grabber}.js`. Takes about 45 minutes (live video, steps 25-37: about 25 more;
finish detection, steps 38-52: about 60 more, half of it the 30-minute endurance run).

## Set-up

- **Devices:** an iPhone with current iOS in Safari (and, once per cup, one on iOS 17), a mid-range Android (Samsung
  A-series) and a Pixel in current Chrome, a laptop. Chargers connected, sound on, the iPhone's silent switch **off**.
- **Event:** an online competition with one solo round of **5 minutes**, online timing enabled, two participants with
  personal links (the Online Test Cup on the dev data has them: `/en/compete/AnonymousTestCupToken2`).
- **Watching the server:** the control room (or SQL, below) shows what each phone recorded.

```sql
-- What a participant's phones recorded, in order (lateMs = arrived how long after its moment)
SELECT e.type, e.device_id, to_timestamp(e.client_ms / 1000.0) AS moment, e.received_at_ms - e.client_ms AS late_ms, e.payload
FROM online_entry_event e JOIN online_entry o ON o.id = e.online_entry_id
JOIN competition_participant_round r ON r.id = o.entry_id
WHERE r.participant_id = :participant ORDER BY e.client_ms;

SELECT kind, to_timestamp(taken_ms / 1000.0), declared_bytes, verified_bytes, uploaded_at_ms IS NOT NULL AS uploaded
FROM online_entry_media m JOIN online_entry o ON o.id = m.online_entry_id
JOIN competition_participant_round r ON r.id = o.entry_id
WHERE r.participant_id = :participant ORDER BY taken_ms;
```

Record per device: model, OS + browser version, pass / fail per step, a screenshot of anything unexpected.

## Steps

| # | Do | Must happen |
|---|----|-------------|
| 1 | Open the link on the **laptop** | Welcome screen without the site's header and footer; "Continue on your phone" with a QR code |
| 2 | Scan that QR with the phone's camera app | The same page opens on the phone, in the phone's browser; no QR card on the phone |
| 3 | Open the link from an **Instagram or Messenger** message | "Open this page in your phone's browser"; Android: "Open in Chrome" opens it in Chrome; iPhone: "Copy the link" copies it |
| 4 | Tick the consent, Continue | The checklist; the camera permission prompt; after Allow a live, **mirrored** front-camera preview with the dashed frame |
| 5 | Watch the rows for 10 s | Camera "On - W × H" (1080p on most phones), Clock "Synced (± N ms)" with N < 100 on Wi-Fi, Connection "Connected", Screen "Asked for when you tap I'm ready" |
| 6 | "Switch camera", then back | Back camera (not mirrored); **Android: the main lens, not the ultra-wide** (normal field of view, autofocus works); back to the front one. A reload keeps the last choice |
| 7 | "Take the photo" (empty mat) | Row turns green "Taken", Photo uploads "Working"; the server lists an `empty_mat` photo |
| 8 | Block the camera for the site in the browser settings, reload, Continue | Camera row red "Blocked …" with Try again; "I'm ready" disabled. Allow it again, Try again → live |
| 9 | "Try a 1-minute practice" | Countdown 10 → 1 with **beeps on 3, 2, 1 and GO**, the stopwatch runs, Finished shows "Your practice time"; at 1:00 an alarm; **the server recorded nothing** |
| 10 | "I'm ready" | Waiting screen, 00:00, "Waiting for the organiser"; `consent.given` + `device.ready` on the server; a `periodic` photo every 30 s |
| 11 | Leave the phone untouched for 3 minutes | The screen never dims or locks (wake lock) |
| 12 | Lock the phone (side / power button) for 20 s, unlock | The page is back with a notice "This page was in the background for …"; the preview moves again within ~2 s (iOS unmutes, Android re-opens); server: `page.hidden`, `page.visible`, `camera.lost`, `camera.back` |
| 13 | Organiser: "Start in 10 s" | Every phone shows the countdown **within a second** (the push; without Mercure within 15 s), beeps on 3-2-1 and GO, Android vibrates at GO. **Film two or three phones side by side: GO within ±100 ms** (criterion P1). Server: `countdown.seen`, `start.seen` (lateMs small), a `start` photo |
| 14 | Read the stopwatch from 1 m away, portrait and landscape | Readable; landscape puts the camera left, the clock and the button right; "MM:SS left" counts down; photos every 30 s |
| 15 | Tap **Finished** | "Finish recorded" with the time (whole seconds, rounded down), "I tapped by mistake (10)" counting down |
| 16 | Tap "I tapped by mistake" within 10 s | Back to the running stopwatch; server: `finish.button` then `finish.button.undone` with that tap's id |
| 17 | Tap Finished again, wait 10 s, "Not complete? Keep puzzling", tap Finished once more | Each tap: a `button` photo and, ~6 s later, a `burst` (~1-2 MB, about 100 frames from 5 s before to 5 s after the tap, the manifest in its `media.uploaded`) |
| 18 | Reload the page with the browser's reload | Straight back to the screen it was on (no welcome, no checklist), the clock at the right time; no second `start.seen`. iOS may ask for the camera again - allow. On the waiting / running screens a "Tap here once" banner until the first touch (browsers play no sound after a reload without one; Safari keeps no screen lock) - do the reload once **during the waiting screen without touching it afterwards**: Android keeps the screen on anyway, the iPhone must not lock once the banner was tapped; after the tap the countdown beeps again |
| 19 | Airplane mode for 2 minutes, tap Finished while offline, airplane mode off | "Offline - everything is kept on this phone …" while offline; the recorded time does not move; when back, the events arrive with their original moments (large `late_ms`) and the photos follow |
| 20 | Open the same link on the **second phone**, go through the checklist, "I'm ready" there | Within ~15 s the first phone shows "Opened on another device" and its camera stops; "Use this device instead" on the first phone takes it back: the second shows the notice and its camera stops within ~15 s (at most 20 s - also when it was readied only a moment before), and neither switches back by itself; tapping back and forth on both, the last tap wins |
| 21 | Let the round run out | Alarm + vibration, "Time's up - Stop puzzling", an `end` photo, the pieces question with the number keyboard; a count over the puzzle's pieces is refused with a message; Send → "Thank you!" with "Pieces left: N"; server: `timeup.pieces` |
| 22 | Leave the after screen open until the status line shows nothing to send | Everything uploaded; closing the tab earlier asks "Leave site?" while something is unsent |
| 23 | Sign in from the welcome screen (anonymous link), come back | The page connects the link to the profile ("Connected to your profile"); an organiser opening a participant's link sees "Preview as organiser" and the page records nothing until "This is me" |
| 24 | Organiser: "Reset" on the stopwatch page after the round (no result confirmed yet), then "Start in 10 s" again | Every phone that was ready stays ready (no checklist), counts down, GO, **the running stopwatch - not "Finish recorded"**, also on a phone opened fresh; at the end the pieces question again. A Reset after a result was confirmed is refused with "Clear them at the results desk first" |

## Live video (rounds with "Live video" on)

Needs a media server the phones reach (LiveKit, contract phase-1b.md) and the round's "Live video", "See each other"
and "Studio" switches on. Devices: an iPhone in Safari and an Android phone in Chrome as the two participants (one of
them on **mobile data**, Wi-Fi off), the organiser's laptop on the control room, a second laptop (or a tablet) on the
studio page as the host. Headphones on the host. After any change to `assets/online/compete_video*.js`,
`assets/online/livekit_session.js` or the video parts of `compete_controller.js`.

| # | Do | Must happen |
|---|----|-------------|
| 25 | Both phones: the checklist, "I'm ready" | Within ~1 s the bar says "Video on"; the control room shows both cameras live (small tiles). The preview never blinks, the camera is never asked for again; photos keep coming every 30 s |
| 26 | Organiser: "Turn on the microphone", hold it (or "Keep talking") and speak | Both phones play the voice and show "… is speaking" on the camera preview; nothing on the page moves |
| 27 | Organiser keeps talking, "Start in 10 s" | The beeps on 3-2-1 and GO stay clearly louder than the voice (the voice is lowered around them - Android; on iOS the voice may not be lowered, the beeps must still be heard) |
| 28 | During the waiting screen: the strip of the others | Each phone shows the other's camera at the bottom; "Hide" hides it and stays hidden after a reload, "Show" brings it back. At the countdown and during the solve the strip is gone; after Finished it is back |
| 29 | Reload a phone on the waiting screen while the organiser talks | Android: the voice plays again at once (Chrome lets a page that uses the camera play sound); iPhone: no voice until "Tap here once", then the voice and the beeps |
| 30 | A phone taps Finished; organiser: "Invite to the studio" on its tile | Within ~1 s "You're invited to the studio" with "2:00 left to answer" counting down. A phone still puzzling is never asked during the solve - the invitation waits for its Finished |
| 31 | Join | The microphone permission prompt (the microphone only - never the camera); "On air" with the host's picture and voice; the host sees the finished puzzle and hears the participant. The camera preview stays live the whole time |
| 32 | Host: "Thank you" | Within ~1 s the phone is back: "Thank you for joining the studio!", still "Finish recorded" with the same time, "Video on"; the microphone indicator of the phone (iOS orange dot / Android icon) goes off |
| 33 | Invite again, "Not now"; invite once more and let it run out | "Not now": the prompt goes; run out: "The invitation to the studio has expired." - never offered again |
| 34 | During a running round, stop the media server (or block it on the phone's network) for a minute, tap Finished meanwhile | The bar says "Video: reconnecting" - no banner, nothing else changes: the clock, Finished (time, photo, burst on the server), the photos. Once the server is back the phone is "Video on" again by itself within ~30 s, never reloading |
| 35 | Open a phone's link on the laptop while the phone is ready | The checklist says the link is in use on another device and offers "Use as a second screen"; there: the round's clock, the host's voice, the others (not while still puzzling), "Watch the studio" opens the watch page, **no Finished**. "Use this device for the camera instead" goes back to the checklist |
| 36 | "I'm ready" on the laptop (takeover) | The phone's bar says "Video off" at once, "Opened on another device" within ~15 s, and its camera leaves the hall (the control room shows the laptop's camera instead) |
| 37 | Organiser switches "See each other" or "Live video" off and back on (online timing page) | The phones follow at their next state fetch (open the page again, or a start): the strip goes / the phone leaves the hall and competes on, then comes back - see the open question in spikes.md S6 |

## Finish detection (rounds with "Finish: Detection" or "detection watching")

The phone detects raised hands itself (README §5.7, stream P2I): MediaPipe from the pinned CDN URLs (OnlineDetectorAssets),
a worker on the page's own camera frames, the set-up test on the checklist, candidates with a photo and a ±5 s burst.
Set the round's "Finish" to **Detection** (`on`) for steps 38-49, to **detection watching** (`shadow`) for 50-51. Devices:
an **iPhone in Safari** and an **Android phone in Chrome** (one older / mid-range one if there is one), each on a stand -
once **angled** (3-4 ft away, ≥ 12" above the table, upper body in view) and once **top-down** (only the table). After any
change to `assets/online/compete_detection*.js`, `detector_feed.js`, `detector_pacing.js`, `finish_machine.js`,
`hands_up_signal.js` or `detector.worker.js`.

```sql
-- The candidates and what the phone said about each (view, score, held, the window), with their evidence
SELECT to_timestamp(e.client_ms / 1000.0) AS moment, e.received_at_ms - e.client_ms AS late_ms,
       e.payload->>'view' AS view, e.payload->>'score' AS score, e.payload->>'heldMs' AS held_ms,
       e.payload->>'mode' AS mode, e.payload->>'fps' AS fps, e.payload->>'inferenceMs' AS inference_ms,
       (SELECT string_agg(m.kind || ':' || coalesce(m.verified_bytes, m.declared_bytes), ', ') FROM online_entry_media m WHERE m.related_event_id = e.id) AS evidence
FROM online_entry_event e JOIN online_entry o ON o.id = e.online_entry_id
JOIN competition_participant_round r ON r.id = o.entry_id
WHERE r.participant_id = :participant AND e.type = 'finish.candidate' ORDER BY e.client_ms;

-- The detector as the phone reported it when it said "I'm ready" (state, view, the set-up test, load time)
SELECT e.device_id, e.payload->'detector' AS detector, e.payload->>'userAgent' AS browser
FROM online_entry_event e JOIN online_entry o ON o.id = e.online_entry_id
JOIN competition_participant_round r ON r.id = o.entry_id
WHERE r.participant_id = :participant AND e.type = 'device.ready' ORDER BY e.client_ms;
```

| # | Do | Must happen |
|---|----|-------------|
| 38 | Open the link (angled stand), consent | The checklist has a "Finish detection" row: "Preparing the camera check…" while ~18 MB download (note how long - Wi-Fi and mobile data); "I'm ready" waits for it. On a slow connection "Skip - use the Finished button" appears after 15 s |
| 39 | Once it is prepared: sit still for 5 s, then **raise both hands above your shoulders** for a second | The row counts down "Raise both hands for a second (N s)", says "Keep them up…" while you hold, then **"Sees your raised hands - camera at an angle"** (green) and a short chime/vibration where allowed. Note the time from raising to the green row |
| 40 | Move the phone to the **top-down** mount (only the table in view), reload, "Test again", show **both open palms** to the camera | "Sees your raised hands - camera from above". Then try the angled raise in the top-down view: it must not be needed - the palms are what this view looks for |
| 41 | "Test again" and do nothing for 20 s | "Detection may miss your finish - use the Finished button" (amber); "I'm ready" still works. A reload keeps it (button-only on this phone) until a test passes. (The page's own first test - started by itself - that never saw hands up just goes back to "Raise both hands once to test it": nobody tried) |
| 42 | "I'm ready", organiser "Start in 10 s", puzzle (or pretend) for 1 minute, **then raise both hands and hold** | Within ~1 s a **green flash and a chime**, "Finish recorded at MM:SS - if a piece is missing, keep going and raise your hands again", the running clock small, the Finished button still there. The control room's tile: "Detection ready" and **"Finished (detected) MM:SS"**, on top of the grid. Server: a `finish.candidate` (view, score, held ≈ 500-700 ms) with a `candidate` photo (hands up, full resolution) and, ~6 s later, a `burst` |
| 43 | Keep the hands up for 5 s, lower them, raise again **within 10 s** | One candidate for the long raise; the second raise inside the cooldown gives none - "Raise your hands again in a moment - or tap Finished" |
| 44 | Wait 15 s, raise again | A second candidate (a later raise after the cooldown is a new one); the screen shows its time |
| 45 | A **premature** raise: raise at 1:00, keep puzzling, raise again at 2:00, tap Finished at 2:03 | Two candidates and a tap. Review: the suggestion is the first candidate; `G` moves to the second, `C` confirms it as "Confirmed from the detection"; the accuracy report shows the detection error, the delta (tap − candidate ≈ 3 s) and one false raise |
| 46 | Gestures that are not a finish: scratch your head, adjust glasses, stretch one arm, wave one hand, put both hands on the table edge | No candidate. Note every false one (with the burst - it is the bench's material) |
| 47 | Lock the phone while holding a raise, unlock | No candidate from before the lock carries over; the gap is in the events (`page.hidden`); a new raise after unlocking is detected |
| 48 | **Endurance:** let the round run 30 minutes with detection on (and live video on, if the media server is there), charger connected | The detector keeps running (the control room's chip stays "Detection ready", its fps in live.json ≥ 2); note **heat** (by hand: cool / warm / hot), **battery %** at start and end (charger off for this one if possible), the phone's own slowdowns, and the **inference ms + fps** from live.json every 5 minutes (`presence.detector`) |
| 49 | Time's up after a detected finish (no tap) | "Time's up" with "Finish recorded at MM:SS - Was your puzzle complete then?" and "My puzzle is complete"; tapping it ends with "Your time MM:SS - being verified"; the pieces question still works for a phone whose puzzle was not complete |
| 50 | Round set to **detection watching** (`shadow`): repeat 42 and tap Finished 2 s after the raise | **Nothing is shown** - no flash, no chime, no "Finish recorded"; the Finished tap is the finish. Server: the candidate with its photo and burst all the same; the control room shows only the tap |
| 51 | Review the shadow round | The suggestion is the tap ("Confirmed from the Finished button" after `C`); the candidate is listed next to it with its photo and burst; the accuracy report lists the delta |
| 52 | A phone without the detector (iOS 16 or older, or a browser in which the row says "This phone can't detect raised hands") | Button-only, said in the row; "I'm ready" works at once; the control room's chip says "Button only" |

## Known platform limits to note, not to fail

- iOS mutes the camera whenever the page is hidden - the review sees it as `camera.lost` / `camera.back`.
- iOS plays the beeps through WebAudio, which the silent switch mutes; the countdown is visual first.
- `navigator.vibrate` does not exist on iOS.
- Home-screen web apps on iOS before 18.4 have no wake lock: the Screen row says to turn auto-lock off.
- A home-screen (PWA) copy of the site reloads itself after 15 minutes in the background (`pwa_lifecycle_controller.js`); the
  page survives a reload (step 18), but open personal links in the browser, not from the home screen.
- iOS ignores an `<audio>` element's volume: the host's voice plays through the page's own AudioContext (lowered there
  around the beeps); if a phone plays the voice from the earpiece after the studio (the microphone switched the audio
  session), note the iOS version.
- Without headphones the studio may echo for a moment - WebRTC's echo cancellation settles within seconds.
- The detector needs WebGL2 in a worker even on the CPU delegate (MediaPipe uploads every frame): iOS before 17 and
  browsers without it compete button-only - that is the design, not a failure.
- MediaPipe logs its own lines (`INFO: Created TensorFlow Lite XNNPACK delegate for CPU`) to the console as errors -
  they are not.
