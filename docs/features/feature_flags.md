# Feature Flags

This file documents all active feature flags in the codebase — where they are, what feature they gate, and when they can be removed.

## XP System (admin-only) — `xp-system`

- **Feature:** XP / Levels / Achievements gamification bundle (`docs/features/xp-levels/`)
- **Flag:** `SpeedPuzzling\Web\Services\Xp\XpFeatureGate` — `isVisibleFor(?PlayerProfile)` restricts visibility to admins; `isEmailSendingEnabled()` suppresses ALL feature emails (achievement congratulations, weekly digest, reveal emails) for everyone while active
- **Gated files** (authoritative checklist in `docs/features/xp-levels/leak-inventory.md`):
  - Components: `BadgesProfileSection`, `XpRing`, `XpSolveReceipt`, `XpRecapCelebration`, `XpPuzzleEstimate`, `XpRevealInvite`
  - Controllers: `BadgesOverviewController`, `AchievementDetailController`, `XpLeaderboardController`, `XpHistoryController`, `XpExplainerController`, `FairPlayXpController`, `XpLaunchRevealController`, `XpShareCardController`, `RevealBadgeController`, `BadgeRevealsController`, `EditTimeController` (delete-dialog XP warning), `EditProfileController` (digest + opt-out form fields)
  - Email suppression: `RecalculateBadgesForPlayerHandler` (badge congratulations), `SendContentDigestConsoleCommand` + `SendPlayerContentDigestHandler` (weekly digest), `SendXpRevealEmailsConsoleCommand` + `SendXpRevealEmailHandler` (reveal email)
- **NOT gated (intentional):** badge evaluation/persistence and XP ledger accrual keep running for everyone — silent accumulation before launch
- **Exempt by decision (OK to leak):** public API responses + Swagger docs
- **Remove when:** XP launch day (`docs/features/xp-levels/launch-runbook.md`) — flip/remove the gate + call sites, DELETE the flag tests (`tests/Controller/XpLeakTest.php`, `XpPagesTest.php`, `XpSurfacesTest.php`, `DigestSettingsVisibilityTest.php`, flag cases in `BadgesOverviewControllerTest` + `RecalculateBadgesForPlayerHandlerTest`), then run the reveal-email command

## Competition Table Layout (admin-only)

- **Feature:** Table layout management for competition rounds
- **Flag:** `is_granted('ADMIN_ACCESS')` check in template
- **Gated files:**
  - `templates/manage_competition_rounds.html.twig` — Tables button visibility
- **Remove when:** Table layout feature is ready for all competition organizers
