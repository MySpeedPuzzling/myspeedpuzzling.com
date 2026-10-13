# Participant page - real-device protocol

The camera, the wake lock, sounds and the browsers' background rules cannot be tested in CI (README §5.5 "Browser
realities"). Run this on real phones before every test cup and after any change to `assets/controllers/compete_controller.js`
or `assets/online/{camera,wake_lock,beeps,frame_grabber}.js`. Takes about 45 minutes.

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
| 20 | Open the same link on the **second phone**, go through the checklist, "I'm ready" there | Within ~15 s the first phone shows "Opened on another device" and its camera stops; "Use this device instead" on the first phone takes it back (the second shows the notice) |
| 21 | Let the round run out | Alarm + vibration, "Time's up - Stop puzzling", an `end` photo, the pieces question with the number keyboard; a count over the puzzle's pieces is refused with a message; Send → "Thank you!" with "Pieces left: N"; server: `timeup.pieces` |
| 22 | Leave the after screen open until the status line shows nothing to send | Everything uploaded; closing the tab earlier asks "Leave site?" while something is unsent |
| 23 | Sign in from the welcome screen (anonymous link), come back | The page connects the link to the profile ("Connected to your profile"); an organiser opening a participant's link sees "Preview as organiser" and the page records nothing until "This is me" |
| 24 | Organiser: "Reset" on the stopwatch page after the round (no result confirmed yet), then "Start in 10 s" again | Every phone that was ready stays ready (no checklist), counts down, GO, **the running stopwatch - not "Finish recorded"**, also on a phone opened fresh; at the end the pieces question again. A Reset after a result was confirmed is refused with "Clear them at the results desk first" |

## Known platform limits to note, not to fail

- iOS mutes the camera whenever the page is hidden - the review sees it as `camera.lost` / `camera.back`.
- iOS plays the beeps through WebAudio, which the silent switch mutes; the countdown is visual first.
- `navigator.vibrate` does not exist on iOS.
- Home-screen web apps on iOS before 18.4 have no wake lock: the Screen row says to turn auto-lock off.
- A home-screen (PWA) copy of the site reloads itself after 15 minutes in the background (`pwa_lifecycle_controller.js`); the
  page survives a reload (step 18), but open personal links in the browser, not from the home screen.
