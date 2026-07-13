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

- [x] Post-solve XP receipt on recap page (`added_time_recap` + `added_tracking_recap`) — verified: `XpSurfacesTest::testRecapShowsNoXpTracesToNonAdminOwner`
- [x] Lazy Live Component `XpRecapCelebration` — gate-checked in every render incl. the live endpoint; renders nothing for non-admins (covered by the recap assertions)
- [x] Profile: avatar XP ring + level chip + progress bar — verified: `XpSurfacesTest::testProfileShowsNoXpTracesToNonAdmins`
- [x] Profile: achievements strip (incl. free-user locked strip + "N waiting" teaser) — matrix inside `BadgesProfileSection`; verified: xp-teaser + ci-medal absence for non-admins
- [x] Badge reveal endpoint (POST, `revealed_at` flip) — 404 while flagged: `XpSurfacesTest::testRevealEndpointIs404ForNonAdmins`
- [x] Membership-activation reveal page (`/my/achievement-reveals` + membership-page invite) — 404/hidden while flagged: `XpSurfacesTest`
- [x] Header avatar XP ring — verified: `XpSurfacesTest::testHeaderShowsNoRingToNonAdmins`
- [x] Puzzle detail XP estimate line — verified: `XpSurfacesTest::testPuzzleDetailShowsNoEstimateToNonAdmins`
- [x] Delete-solve dialog XP warning line — controller passes 0 while flagged (template renders only when > 0); re-verified by P8 leak test

## Pages (P5)

- [x] Achievements catalog rework (route + `/badges` 301 redirect) — 404 for non-admins: `XpPagesTest` + `BadgesOverviewControllerTest`
- [x] Achievement holders directory (`/achievements/{type}`) — 404 for non-admins: `XpPagesTest`
- [x] XP leaderboard (`/players/xp-leaderboard`) — all three tabs 404 for non-admins: `XpPagesTest`
- [x] XP audit page (`/my/xp-history`) — 404 for non-admins: `XpPagesTest::testXpHistoryIs404ForNonAdmins`
- [x] Explainer page (`/how-xp-works`) — 404 for non-admins: `XpExplainerControllerTest` + `XpPagesTest`
- [x] Fair-play page (`/fair-play-xp`) — 404 for non-admins: `FairPlayXpControllerTest` + `XpPagesTest`
- [x] Launch reveal page (`/my/xp-reveal`) — 404 for non-admins: `XpPagesTest::testLaunchRevealIs404ForNonAdmins`
- [x] Share-card image routes (`/xp-card/{playerId}/{launch|level-up}`) — 404 for anonymous + non-admins (direct URL access covered): `XpPagesTest`

## Emails & notifications

- [ ] Badge congratulation email (see retrofit above; post-launch: members-only, P2.T6)
- [x] Weekly content digest (P6) — dispatch command warns+exits AND handler short-circuits while flagged (SendPlayerContentDigestHandlerTest::testSuppressedWhileFeatureFlagActive)
- [x] Digest preference in messaging settings (P6) — hidden while flagged, incl. the experience-system opt-out checkbox (DigestSettingsVisibilityTest)
- [ ] One-time reveal email command (P7) — refuses to run while flagged
- [ ] In-app notifications — none planned; verify none get added
- [ ] Activity feed — verify no XP/achievement entries appear

## SEO / discovery

- [x] Sitemap: new pages excluded while flagged — none of the XP routes are referenced by any Sitemap*Controller (verified by grep)
- [x] No links/menu items to gated pages rendered for non-admins — all links live inside gate-checked components/pages (profile strip, catalog, receipt, membership invite); header/menu untouched

## Final pass

- [ ] P8.T2 leak WebTestCase green (anonymous + logged non-admin non-member across all surfaces)
