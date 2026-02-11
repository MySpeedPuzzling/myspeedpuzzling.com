# Claude Code Implementation Prompts

Feed these prompts to Claude Code **one at a time**, in the order listed below. Each prompt is self-contained and references the feature specs and implementation plan.

## Recommended Order

| # | File | Phase | Est. Size | Dependencies |
|---|------|-------|-----------|-------------|
| 1 | `phase-2-reserved-status.md` | Reserved Status | Small | None |
| 2 | `phase-3-shipping-settings.md` | Shipping Settings | Small | None |
| 3 | `phase-5-messaging-core.md` | Messaging System | **Large** | None (Mercure done) |
| 4 | `phase-4-marketplace-page.md` | Marketplace Page | Medium | Phase 2 + 3 |
| 5 | `phase-6-marketplace-messaging-integration.md` | Marketplace ↔ Messaging | Small | Phase 4 + 5 |
| 6 | `phase-10-puzzle-detail-integration.md` | Puzzle Detail Integration | Medium | Phase 4 |
| 7 | `phase-7-ratings.md` | Transaction Ratings | Medium | Phase 5 (loosely) |
| 8 | `phase-8-admin-moderation.md` | Admin Moderation | Medium-Large | Phase 5 |
| 9 | `phase-9-email-notifications.md` | Email Notification Cron | Small-Medium | Phase 5 |

## How to Use

1. Copy the content of the prompt file
2. Paste it into Claude Code
3. Let it implement, review the output
4. Run migrations if generated: `docker compose exec web php bin/console doctrine:migrations:migrate`
5. Verify with the quality checklist (PHPStan, CS, tests, schema validation)
6. Move to the next phase

## After Each Phase

Always run:
```bash
docker compose exec web composer run phpstan
docker compose exec web composer run cs-fix
docker compose exec web vendor/bin/phpunit --exclude-group panther
docker compose exec web php bin/console doctrine:schema:validate
docker compose exec web php bin/console cache:warmup
```

## Important Notes

- Each prompt tells Claude Code to NOT run migrations — that's for you to do manually
- Prompts reference the feature specs in the parent directory for full context
- Phase 5 (Messaging) is the largest — consider reviewing in chunks
- All prompts assume the `SpeedPuzzling\Web` namespace and existing codebase patterns
