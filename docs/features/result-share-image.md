# Result share image

`GET /result-image/{timeId}` (`ResultImageController` → `GetResultImage`) draws an 800×800 JPEG card of one result:
the finished photo (else the box) under two overlays, puzzle name, brand, pieces, time, PPM, the player's name (none
for a private profile), the rank and the player's code.

## Who opens it

Not an og:image anywhere. Three consumers, all the player's own:

1. the recap page after adding / editing a time (`added_time_recap.html.twig`, `<img src>`) - the first request draws it;
2. the Share button there and in the ☰ menu of the own results (`share_image_controller.js` fetches it and hands the
   file to `navigator.share`);
3. the service worker, which answers the recap `<img>` stale-while-revalidate (and never stores a `no-store` answer,
   so a placeholder card is not replayed).

## Kept on the server: 48 hours

`ResultImageCache` keeps a drawn card on local disk for `KEEP_HOURS` (48) - see the class for why not in the bucket.
A kept card's path carries a hash of what it shows (`ResultImageVersion`: time, puzzle name, brand, pieces, how many
people, the photo, the player's name and code, privacy, a `DRAWING` constant to bump when the drawing changes), so an
edited time, a rename, a new photo or a privacy switch draws a new card at once; cards of before just expire. The rank
line is left out on purpose: it is drawn with the card and stays while the card is kept (knowing it would cost the
ranking query on every request).
A card drawn over the placeholder photo (photo missing / hidden until a round starts) is never kept and answered
`no-store`.

## Kept in the browser: at most an hour (2026-11)

A kept or freshly drawn card is answered `Cache-Control: private, max-age=N` with N = min(3600, what the server keeps
it for), a strong `ETag` (xxh128 of the bytes) and `Last-Modified` (when it was drawn); a revalidation of an unchanged
card is a `304` without the body.

- **private**: only the player's own browser keeps it. A shared cache (Cloudflare) could otherwise keep serving the
  named card after the profile turned private.
- **never beyond the server**: a card the server draws again in 30 minutes is kept by the browser for 30 minutes.
- The recap page puts the same hash into the card's address (`?v=…`, ignored by the route): after an edit neither the
  browser nor the service worker has that address, so the recap shows the edited card. The ☰ menu's share button
  uses the plain address, so a card shared from there before an edit can come from the browser for up to an hour.
- The card does not depend on who asks, so the session listener's `max-age=0` is turned off for it
  (`AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER`) - otherwise every signed-in request would be uncacheable.

## Drawing and encoding

Progressive JPEG, quality 88 (`ResultImageJpeg`, sRGB always - a CMYK print file would show wrong otherwise);
`ImageMetadata::strip()` runs before it - no EXIF. The text goes through `ResultImageText`: lines, breaks and places come
from Intervention's layout, the outline is one native stroke instead of Intervention's nine shifted copies. Together
(2026-11-10, Jan chose it over a Rust or libvips sidecar after a before/after): drawing 370 → about 110 ms, the file
567 → 105 kB, on a Mac on a 2000 px photo. A sidecar would have saved about 50 ms more per card on ~1,450 requests a day.

Sentry spans of op `result_image` show the steps of a drawn card: `photo read`, `draw`, `encode`.

On the recap page the card sits in a fixed square with a shimmer until it arrives (`result_card_frame_controller.js`).
The shimmer is behind the card, so it needs no script to end.

`GetResultImageTest::testTheCardShowsWhatWasDrawn` compares the card with a reference
(`tests/fixtures/result-image/time06.jpg`, regenerate command in the test) - the guard for any change to the drawing.

## Not done yet

- drawing the card in the background right after the save, if the `photo read` and `draw` spans still justify it.
