# XP / Achievements — Leak-Surface Inventory

Every user-facing surface of the gamification bundle must be gated by `XpFeatureGate`
(`src/Services/Xp/XpFeatureGate.php`, flag `xp-system` in `docs/features/feature_flags.md`) while
the flag is active. **Tick a row ONLY after verifying the surface as a non-admin (and as anonymous
where applicable) shows NOTHING** — no XP, level, achievement, or badge traces.

Rules while flagged:

- Non-admins see nothing and receive **no emails, digests, or notifications**.
- Badge + XP **persistence keeps running for everyone** (silent accumulation is intended).
- **Exempt by decision (OK to leak): public API responses + Swagger docs.**

## Already-shipped badge surfaces (retrofit, P0.T4)

- [x] Profile badges section (`BadgesProfileSection` component) — renders nothing for non-admins (verified: `BadgesOverviewControllerTest::testProfileBadgesSectionHiddenFromNonAdminsWhileFlagged`)
- [x] Badges catalog page + route (`BadgesOverviewController`, `badges_overview`) — 404 for non-admins (verified: anonymous + logged non-admin WebTestCases)
- [x] Badge congratulation email (`RecalculateBadgesForPlayerHandler` → `SendBadgeNotificationEmail`) — dispatch short-circuited while flagged (verified: `RecalculateBadgesForPlayerHandlerTest::testEmailDispatchSuppressedWhileFlagged`)

## Solve-loop surfaces (P4)

- [ ] Post-solve XP receipt on recap page (`added_time_recap`)
- [ ] Lazy Live Component `XpRecapCelebration` — level-up/achievement celebrations
- [ ] Profile: avatar XP ring + level chip + progress bar
- [ ] Profile: achievements strip (incl. free-user locked strip + "N waiting" teaser)
- [ ] Badge reveal endpoint (POST, `revealed_at` flip)
- [ ] Membership-activation reveal page
- [ ] Header avatar XP ring
- [ ] Puzzle detail XP estimate line
- [ ] Delete-solve dialog XP warning line

## Pages (P5)

- [ ] Achievements catalog rework (route + `/badges` alias/redirect)
- [ ] Achievement holders directory (`/achievements/{type}`)
- [ ] XP leaderboard (`/players/xp-leaderboard`) — all tabs incl. AP tab
- [ ] XP audit page (`/my/xp-history`)
- [ ] Explainer page (public post-launch; gated while flagged)
- [ ] Fair-play page (public post-launch; gated while flagged)
- [ ] Launch reveal page
- [ ] Share-card image routes (level-up + launch cards — **direct URL access!**)

## Emails & notifications

- [ ] Badge congratulation email (see retrofit above; post-launch: members-only, P2.T6)
- [ ] Weekly content digest (P6) — dispatch command + handler both suppressed while flagged
- [ ] Digest preference in messaging settings (P6) — hidden while flagged
- [ ] One-time reveal email command (P7) — refuses to run while flagged
- [ ] In-app notifications — none planned; verify none get added
- [ ] Activity feed — verify no XP/achievement entries appear

## SEO / discovery

- [ ] Sitemap: new pages excluded while flagged
- [ ] No links/menu items to gated pages rendered for non-admins (header, profile, recap, footer)

## Final pass

- [ ] P8.T2 leak WebTestCase green (anonymous + logged non-admin non-member across all surfaces)
