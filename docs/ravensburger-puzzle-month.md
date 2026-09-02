# Ravensburger Puzzle Month

Ravensburger prints a MySpeedPuzzling link on the box of every "Puzzle Month" edition.
The box goes to print months before the puzzle has its final name and image, so each
edition starts life as a **hidden placeholder puzzle** that already owns its final URL,
and is filled in and revealed later.

Everything here is done by hand (SQL on the production box + a one-line code change per
edition). It happens once a month at most, so there is deliberately no console command
or admin UI for it - this document is the runbook.

## What the box carries

| Printed on the box | Where it goes |
|---|---|
| `https://myspeedpuzzling.com/ravensburger-puzzle-month/{edition}` | `RavensburgerPuzzleMonthRedirectController` → 302 to the edition's puzzle detail in the visitor's language, tagged `utm_source=puzzle_box&utm_campaign=ravensburger-puzzle-month-{edition}` |
| QR code | `https://myspeedpuzzling.com/ravensburger-puzzle-month/{edition}/qr-code.png` renders the branded QR (same style as the per-puzzle one, logo in the middle) **encoding the human-readable link above**, never the puzzle uuid |

The edition → puzzle mapping is the constant `RavensburgerPuzzleMonthEditions::PUZZLE_IDS`
(`src/Value/`). It lives in code because the printed link must keep working forever and
must never depend on a database row that someone could edit or merge away. The redirect is
a **302** on purpose: browsers cache a 301 permanently, and the target can still change
(placeholder merged into an existing puzzle, for example). Switch to 301 only if there is a
reason to.

## Why a placeholder works: `hide_until`

The `puzzle` table has two embargo columns, originally built for secret competition puzzles:

| Column | Effect while in the future |
|---|---|
| `hide_until` | Puzzle is excluded from **every** listing: search, brand hub, pieces hub, related puzzles, puzzle picker, sitemap, and the public API returns 404 for it. The web detail page still renders it by id - that is exactly what the box link needs. |
| `hide_image_until` | Image is replaced by the placeholder everywhere, and the detail page emits `noindex, nofollow`. |

A placeholder sets **both** to a far-future date. `hide_image_until` matters even with no
image: it is what keeps the placeholder page out of Google (the `robots` block in
`puzzle_detail.html.twig` only checks the image embargo).

The placeholder is created **approved** so the page has no "waiting for approval" banner and
the record is complete the moment it is revealed. (Puzzle approval is a plain DB flag with no
code path, so this is consistent with how puzzles are approved today.)

While hidden, anyone with the link can still add a solving time or put the puzzle in a
collection - fine, it *is* the real puzzle record. Do **not** give the placeholder an EAN before
the reveal: the EAN lookup used by the barcode scanner is the one query that ignores `hide_until`.

## Editions

| Edition | Puzzle id | Created | Status |
|---|---|---|---|
| 1 | `01a0641a-67fd-72f5-859f-31c3b91ae584` | 2026-09-02 | placeholder "Puzzle Month #1", hidden until reveal |

Ravensburger manufacturer id on production: `2e6ea6b1-6ef8-46d7-8445-fd2d77cfd09c`
(not "MyRavensburger" - that is their custom-print brand).

## Runbook: new edition

### 1. Create the placeholder on production

```bash
ssh -o IdentitiesOnly=yes -i ~/.ssh/id_rsa root@lily.srv.thedevs.cz
cd /srv/myspeedpuzzling
ID=$(docker compose exec -T web php -r 'require "vendor/autoload.php"; echo Ramsey\Uuid\Uuid::uuid7()->toString();' </dev/null)
echo "$ID"
docker compose exec -T db psql -U speedpuzzling -d speedpuzzling -P pager=off -v ON_ERROR_STOP=1 -c "
INSERT INTO puzzle (id, pieces_count, name, approved, image, image_ratio, manufacturer_id,
    alternative_name, added_by_user_id, added_at, identification_number, ean, is_available,
    hide_image_until, hide_until)
VALUES ('$ID', 500, 'Puzzle Month #2', true, NULL, NULL, '2e6ea6b1-6ef8-46d7-8445-fd2d77cfd09c',
    NULL, NULL, now(), NULL, NULL, false,
    '2099-01-01 00:00:00', '2099-01-01 00:00:00');" </dev/null
```

Adjust `pieces_count` and the name. If the launch date is already known, put it in both
embargo columns instead of 2099 and the puzzle reveals itself with no further action.

Note the `docker compose exec -T` + heredoc gotcha from the production notes: when scripting
this over ssh, put the commands in a file on the box and run it with `</dev/null`, or the
first `exec -T` swallows the rest of the script.

### 2. Register the edition in code

Add the line to `RavensburgerPuzzleMonthEditions::PUZZLE_IDS`, add the row to the
"Editions" table above, commit, push - the deploy makes
`/ravensburger-puzzle-month/{edition}` and its `qr-code.png` live.

### 3. Hand over the print assets

- Link: `https://myspeedpuzzling.com/ravensburger-puzzle-month/{edition}`
- QR: `https://myspeedpuzzling.com/ravensburger-puzzle-month/{edition}/qr-code.png`
  (600×600 PNG, error-correction level H so the centre logo does not break scanning)

Before the deploy is out, the same PNG can be rendered locally through the test container
(`GeneratePuzzleQrCode::generateForUrl()` - see the test `RavensburgerPuzzleMonthQrCodeImageControllerTest`).

## Runbook: reveal

Once the real name and box photo are known:

1. **Name + image** - open the puzzle page signed in as admin, use *Propose changes* with the
   final name and the photo, then approve it in the admin puzzle-change-request queue (overrides
   are possible there). This goes through the normal handler, so the image gets its SEO
   filename and the puzzle statistics/insights keep working.
2. **Unhide** - clear both embargo columns on production (or wait, if a real date was set):

```bash
docker compose exec -T db psql -U speedpuzzling -d speedpuzzling -P pager=off -c "
UPDATE puzzle SET hide_until = NULL, hide_image_until = NULL
WHERE id = '01a0641a-67fd-72f5-859f-31c3b91ae584';" </dev/null
```

3. Add the EAN / identification number through the same change-request flow if known.
4. Update the "Editions" table above.

The puzzle then appears in search, the Ravensburger brand hub, the sitemap and the API on the
next request (the sitemap and hubs are cached briefly at the edge).

## Related

- Competition secret puzzles use the same columns: `docs/features/competitions-management/README.md`
  §"Hide Until Round Starts" and `AddPuzzleToCompetitionRoundHandler`.
- Per-puzzle QR codes (`/p/{id}`, `/puzzle/{id}/qr-code.png`): `PuzzleQrRedirectController`,
  `PuzzleQrCodeImageController`, `GeneratePuzzleQrCode`.
