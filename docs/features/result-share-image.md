# Result share image

`GET /result-image/{timeId}` (`ResultImageController` → `GetResultImage`) draws an 800×800 PNG card of one result:
the finished photo (else the box) under two overlays, puzzle name, brand, pieces, time, PPM, the player's name (none
for a private profile), the rank and the player's code.

## Who opens it

Not an og:image anywhere. Three consumers, all the player's own:

1. the recap page after adding / editing a time (`added_time_recap.html.twig`, `<img src>`) - the first request draws it;
2. the Share button there and in the ☰ menu of the own results (`share_image_controller.js` fetches it and hands the
   file to `navigator.share`);
3. the service worker, which answers the recap `<img>` stale-while-revalidate.

## Kept on the server: 48 hours

`ResultImageCache` keeps a drawn card on local disk for `KEEP_HOURS` (48) - see the class for why not in the bucket.
A private profile's card has its own path (`…-hidden.png`), so switching privacy draws the other card at once.
A card drawn over the placeholder photo (photo missing / hidden until a round starts) is never kept and answered
`no-store`.

## Kept in the browser: at most an hour (2026-11)

A kept or freshly drawn card is answered `Cache-Control: private, max-age=N` with N = min(3600, what the server keeps
it for), a strong `ETag` (xxh128 of the bytes) and `Last-Modified` (when it was drawn); a revalidation of an unchanged
card is a `304` without the body.

- **private**: only the player's own browser keeps it. A shared cache (Cloudflare) could otherwise keep serving the
  named card after the profile turned private.
- **never beyond the server**: a card the server draws again in 30 minutes is kept by the browser for 30 minutes.
- The card does not depend on who asks, so the session listener's `max-age=0` is turned off for it
  (`AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER`) - otherwise every signed-in request would be uncacheable.

## Encoding

Opaque 8-bit RGB PNG (`ResultImagePng`) instead of Intervention's RGBA: every pixel of the card is opaque, so the
pixels are identical (`ResultImagePngTest` compares them) and the file is ~10 % smaller; zlib level 6 encodes a third
faster than the default for ~1 % more bytes. `ImageMetadata::strip()` runs before it - no EXIF / text chunks.

`GetResultImageTest::testTheCardShowsWhatWasDrawn` compares the card with a reference
(`tests/fixtures/result-image/time06.png`, regenerate command in the test) - the guard for any change to the drawing.

## Not done yet (changes how the card looks - needs Jan's visual yes)

- decode the photo with a size hint (`jpeg:size`) - several times cheaper for 4000 px phone photos;
- one native Imagick stroke per text line instead of Intervention's 9 offset draws (~half of the drawing time);
- JPEG instead of PNG (~10× smaller; the share controller hard-codes `image/png` + `.png`, the kept path would change).
