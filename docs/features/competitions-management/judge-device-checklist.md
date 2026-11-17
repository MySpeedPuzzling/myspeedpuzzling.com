# Judge & competitor phones - real-device checklist

What the automated real-browser suite (`tools/judge-chaos/`, Linux Chrome in Docker) cannot prove: real cameras, iOS
Safari's storage rules, screen lock, battery savers. Walk this once per release of the live entry on **one iPhone
(Safari, current iOS) and one mid-range Android (Chrome)** before an event, and give each judge the "Before the event"
part. There are no native apps - only the website (and its "Add to Home Screen" form). Background:
[judge-offline.md](judge-offline.md), [live-results.md](live-results.md), [competitor-pages.md](competitor-pages.md).

## Before the event (every judge, on the venue's network if possible)
1. Sign in on the phone's browser, open the round's live entry once **with a connection** (the page stores the round
   and the offline copy - the unsent-results sheet then says "Ready offline … (saved HH:MM)").
2. Open every round you will judge once (or let the page prefetch rounds within ±12 h - up to 4).
3. Allow the camera when the scanner asks (Settings → Safari → Camera = Allow / Chrome site settings → Camera).
4. Turn off Low Power Mode (iPhone) / Battery Saver (Android) and set Auto-Lock to 5 minutes or Never for the event.
5. Charge to 100 %; a power bank for events over 3 hours.

## Walk - iPhone, Safari
| # | Step | Expected |
|---|---|---|
| 1 | Open live entry online, wait for "Ready offline" | sheet shows the saved time |
| 2 | Airplane mode, pull to reload | the stored copy loads (strip "No connection - this is the round as this device knew it at …"), Find works |
| 3 | Enter 3 results offline (Finished now, typed time, Didn't finish with missing pieces) | "3 waiting - offline" |
| 4 | Lock the screen 2 min, unlock | page still there, 3 waiting, the stopwatch shows the right time |
| 5 | Airplane mode off | all 3 sent within seconds, no duplicate on the desk |
| 6 | Scan a printed tag of each kind (person, pair/team, table, entry) online, then again in airplane mode | the right entry opens each time (zbar from the phone's cache offline) |
| 7 | Switch to another app for 10 min, come back | page live again, nothing lost |
| 8 | Low Power Mode on, repeat 3-5 | the same (Low Power slows background fetches; sending resumes when the page is visible) |
| 9 | "Add to Home Screen", open it | see the iOS notes below - it opens the home page, signed out |

## Walk - Android, Chrome
Steps 1-8 as above (airplane mode; Battery Saver instead of Low Power Mode), plus: Chrome "Install app" prompt is
not needed - the page works in the browser tab.

## Competitor phone (time proposals)
| # | Step | Expected |
|---|---|---|
| 1 | Signed in as the linked person, open your own page before the start | Stop button appears when the round runs |
| 2 | Screen stays on during the round | the page holds a wake lock (if the OS allows) |
| 3 | Airplane mode, tap Stop, confirm | "Saved on this phone", the time of the tap kept |
| 4 | Back online after 30-60 s | sent; the judge card shows the tap time as the default with a neutral "sent after reconnecting" note |
| 5 | Reload the page while the tap is still unsent | the tap is still there and is sent with its original time |

## iOS specifics to know
- **7-day storage eviction:** Safari deletes a site's script-written storage (IndexedDB, localStorage, service-worker
  caches) after 7 days without the user visiting the site in Safari. For a judge this means: **open the live entry
  online within the last days before the event** (step 1 above always fixes it). Unsent results are never at risk in
  practice - they exist only minutes to hours.
- **Home-screen web app = a separate browser:** an icon added with "Add to Home Screen" has its own cookies and storage,
  separate from Safari - the judge is signed out there, its offline copy and outbox are separate, and our manifest's
  `start_url` is `/` (the home page, not the live entry). Home-screen apps are exempt from the 7-day eviction.
- No `BarcodeDetector` in Safari - the self-hosted zbar decoder always does the scanning (stored on the phone when the
  page prepared offline).
- Screen Wake Lock works in Safari 16.4+ (also for home-screen apps from iOS 18.4); the judge page itself does not
  request one - the judge's phone should have Auto-Lock off or long.

## Recommendation: a PWA install prompt for judges?
**Not now.** A judge works in the browser tab they were signed in with; an installed icon on iOS is a separate, signed-out
browser whose start page is the site's home page, so a prompt would add a sign-in and a "where is my round" step at
the worst moment, and its storage would hold a second outbox. The offline copy, snapshots and outbox already work in
the normal tab on both platforms. Worth it only later, with a judge-specific manifest (`start_url` = the event's live
entry, `scope` `/live`) and a sign-in step inside the installed app - see `docs/TODO.md` "Competition toolkit v2".
