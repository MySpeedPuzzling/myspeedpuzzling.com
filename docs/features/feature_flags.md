# Feature Flags

This file documents all active feature flags in the codebase — where they are, what feature they gate, and when they can be removed.

## XP System (admin-only) — `xp-system`

- **Feature:** XP / Levels / Achievements gamification bundle (`docs/features/xp-levels/`)
- **Flag:** `SpeedPuzzling\Web\Services\Xp\XpFeatureGate` — `isVisibleFor(?PlayerProfile)` restricts visibility to admins; `isEmailSendingEnabled()` suppresses ALL feature emails (achievement congratulations, weekly digest, reveal emails) for everyone while active
- **Gated files** (grows as phases land — authoritative checklist in `docs/features/xp-levels/leak-inventory.md`):
  - `src/Component/BadgesProfileSection.php` — profile badges section renders nothing for non-admins
  - `src/Controller/BadgesOverviewController.php` — badges catalog page 404s for non-admins
  - `src/MessageHandler/RecalculateBadgesForPlayerHandler.php` — badge congratulation email dispatch short-circuited
- **NOT gated (intentional):** badge evaluation/persistence and XP ledger accrual keep running for everyone — silent accumulation before launch
- **Exempt by decision (OK to leak):** public API responses + Swagger docs
- **Remove when:** XP launch day — flip/remove the gate + call sites, delete the leak WebTestCase (P8.T2 of the implementation plan), then run the reveal-email command

## Competition Table Layout (admin-only)

- **Feature:** Table layout management for competition rounds
- **Flag:** `is_granted('ADMIN_ACCESS')` check in template
- **Gated files:**
  - `templates/manage_competition_rounds.html.twig` — Tables button visibility
- **Remove when:** Table layout feature is ready for all competition organizers
