# Badges — UI handoff (state after the badge-art lab, 2026-10-10)

For the next session that fine-tunes the badge UI. The ART is final and lives in `tools/badge-art/` (generator,
frozen bases, icons, QA, docs, performance) and `public/img/badges/` (generated SVG/WebP/PNG + `manifest.json`).
This page lists what the UI already does, what the owner decided, and what is left — with the findings from the
prototypes, so you can go straight to delivery.

## 1. Owner decisions (Jan, 2026-10-10)
- Frame grammar **F1 seal ladder v2**: T1 circle → T2 6 big scallops → T3 12 scallops → T4 12 scallops + pleats +
  tails → T5 sunburst + tails + crown + prism tones. **No sparkle stars, no white gloss arcs** on the bases.
- **17 illustrated icons** (one per `BadgeType`), centred, MSP outline style; the icon accent takes the tier tint.
- **One-off / single-tier badges**: **F1S-A ribbon seal** in a set colour (coral, sky, lavender, teal); the icon accent
  follows the set colour unless the badge's meaning needs its own colour. Supporter = coral.
- Ship **SVG + WebP (site, `cwebp -sharp_yuv -q 90`) + PNG (e-mail)**; everything generated on a dev machine and
  committed; badges = frozen base + icon, only the icon changes per achievement.
- v1 motion: **static badges + the last-piece reveal** on the badge reveal page and the XP launch reveal.
- XP launch reveal: all badges one by one, **crescendo** order (best last), auto-assemble + tap to continue, running XP
  counter, **level medallion finale**, every badge individually + "Skip to summary".
- Everything UI beyond this may be fine-tuned in a separate session (this file).

## 2. What this branch already does
| Area | Files | Notes |
|---|---|---|
| Twig API | `src/Twig/BadgeArtTwigExtension.php`, `src/Services/Badges/BadgeArtManifest.php`, `src/Exceptions/BadgeArtMissing.php` | `badge_art(type, tier, display_px, locked, attrs)` and `badge_art_url(type, tier, format, px, absolute)`; `?v=<manifest version>`; missing art throws in debug, logs a warning in prod |
| Surfaces | `templates/components/BadgesProfileSection.html.twig` (+ `src/Component/BadgesProfileSection.php`), `badges_overview`, `achievement_detail`, `_achievement_info_modal` (`?tier=` in `AchievementInfoController`), recap progress card (`XpRecapCelebration`, `templates/added_time_recap/_achievements.html.twig`) | profile 72 px (flip + NEW pill; free owner sees greyed badges with a lock), catalogue 96 + tier row 48, modal 160 + 48, detail 160/72/24, recap: last-piece stage 88 (Gold and above 144), revealed row 48 |
| E-mails | `templates/emails/_badge_row.html.twig`, `badges_earned`, `content_digest/_xp_block`, `_next_achievements` (`_week_in_numbers`: plural bug fixed) | 96 px PNG shown at 48, absolute URLs, `alt=""` on purpose (name + tier are printed next to it) |
| Locked state | `assets/styles/components/_badge-art.scss` | CSS greyscale + 45 % opacity, no extra assets |
| Reveal (badge reveal page) | `assets/controllers/last_piece_reveal_controller.js`, `assets/last_piece_reveal/` (3×3 geometry JSON, confetti, sound, tier looks), `assets/styles/_last-piece-reveal.scss`, `assets/img/last-piece-reveal-tray.svg`, `templates/badge_reveals.html.twig` | 3×3 correct jigsaw, icon piece clicks in last, piece confetti, T5 shimmer (no stars), sound OFF by default (toggle in localStorage), reduced motion = instant, Turbo-safe, lazy (~22 kB gz) |
| XP launch reveal | `src/Query/GetXpLaunchRevealBadges.php`, `src/Results/XpLaunchRevealBadge.php`, `src/Controller/XpLaunchRevealController.php`, `assets/controllers/xp_launch_reveal_controller.js`, `templates/xp_launch_reveal.html.twig` | members: crescendo deck (single-tier right before the top tier), name + tier + "✓ requirement" + "+XP", tap/button, Skip to summary, counter starts at puzzle-only XP and lands on the player's XP, level finale, summary grid; free players: level + greyed badges + become-a-member |
| XP counter | `assets/controllers/xp_count_controller.js` | now ends on the server's own formatting ("3 500" — `number_format(0, '.', ' ')` in every locale) |
| Rebuild the reveal geometry | `tools/badge-art/reveal/geometry.py` | reproduces the JSON + tray SVG byte-identically (needs the pinned variable Rubik font) |
Tests: `tests/Twig/BadgeArtTwigExtensionTest.php`, `tests/BadgeArtCoverageTest.php` (every type × tier × format × size on
disk), `tests/Controller/BadgeArtSurfacesTest.php`, `tests/Twig/BadgesEarnedEmailRenderingTest.php`,
`tests/Controller/LastPieceRevealPagesTest.php`, `tests/Query/GetXpLaunchRevealBadgesTest.php`.

## 3. Fine-tuning backlog (verified ideas, in priority order)
1. **Real-device pass** of both reveals (iPhone Safari + Android Chrome): haptics (`navigator.vibrate` on Android only),
   sound toggle placement, tap targets, the hover space the icon piece needs (~50 px above a 300 px stage), and the
   45 % locked look on OLED. Playwright WebKit passed; no physical device was tested.
2. **Holo tilt + medal back in the detail modal** (prototype P3, not in v1): one style-agnostic layer over any badge
   bitmap — T1–T3 none, T4 subtle foil rim, T5 "masterpiece" (prism bands, glitter, jigsaw etch in the light); tap
   flips to the back ("No. 0042 of 1 318 · 3.2 % of puzzlers" + date, all 6 locales). 60 fps in Chrome; the per-badge
   bitmap analysis (60–480 ms in the browser) should be precomputed by `tools/badge-art` at build time. Write it from
   scratch — `pokemon-cards-css` is GPL. Needs rarity + serial data (per-type holder counts, earn order).
3. **Self-animating T4/T5 SVG shine** (prototype P5, not in v1): CSS @keyframes inside the badge SVG, works as plain
   `<img>`, 1–2 kB gzip per file; loop only in the modal/reveal, "-once" variants elsewhere (WCAG 2.2.2); Safari's real
   Reduce Motion inside `<img>` needs a device check (WebKit bug 283894). Could be emitted by `tools/badge-art` as an
   extra SVG variant.
4. **Badge cabinet = a jigsaw board on the profile** (prototype P1, not in v1): 6×3 board on desktop / 3×6 on phones,
   earned pieces show the badge on a slice of a yearly picture, empty sockets show a ghost icon, last piece highlighted,
   completion reveals the picture. `GetBadgeCatalog` already returns the data; estimate 5–7 dev days. Open owner
   questions: yearly reset (recommendation: never reset, swap the picture), is the picture too busy, 40 types on a
   phone (~2 screens) vs themed boards.
5. **Event passport** for one-off event badges (prototype P6): deterministic stamps from event data (frame = event
   type, ink = tier). Use F1S-A one-offs for the badges themselves; the passport is a page-level idea.
6. **Spinning medal** for the reveal (prototype P4, optional): toon turntable MP4 105 kB / AVIF 203 kB, or a GLB in
   `<model-viewer>` (Apache-2.0, ~290 kB gz JS loaded only in the modal).
7. Lossless vs lossy check of the 48 px e-mail PNGs in Outlook/Gmail dark mode (white keyline around the seal?).

## 4. Prototypes (local, outside git)
The prototypes live in the lab next to the repo: `.claude/worktrees/badge-lab/poc/` — `P1-badge-cabinet`,
`P2-last-piece-reveal`, `P3-holo-modal`, `P4-spinning-medal`, `P5-animated-svg`, `P6-event-passport`, `BK2-bakeoff`
(grammar switcher across every surface), `RV2-app-reveal` (screenshots + demo of the shipped reveals). Each has an
`index.html` that opens from disk and a README with measurements. The lab is not versioned; if it is gone, sections
2–3 above are enough to rebuild. Research behind the art (tier systems, style spec, model/licence findings):
`.claude/worktrees/badge-lab/research/` and `review/FINAL.html`.

## 5. Gotchas learned
- **Never ship FLUX.2 klein 9B outputs** (non-commercial licence); the art pipeline uses no AI at all.
- The badge art is pre-rendered per display size (never downscale 1024 px in the browser) and cache-busted by the
  manifest version — re-run `tools/badge-art/build.py` + commit after any art change, never hand-edit files.
- Turbo: every reveal controller must clean up timers/audio in `disconnect()`; prefers-reduced-motion = instant.
- Web Audio needs a user gesture (first tap) — keep sound off by default.
- Inline SVG badges in one page would collide on gradient/filter ids — use `<img>`.
