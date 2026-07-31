# Activity Analytics

Status: **implemented** (2026-07-31). Daily player-presence tracking for internal analytics and marketing decisions (DAU/WAU/MAU, retention, growth by locale). Deliberately coarse: presence per UTC day, no IP, no user agent, no page paths — the detail level was a conscious privacy decision, not a starting point.

Storage decision (Jan, 2026-07-31): **plain Postgres, same DB** — no time-series store. At ~10k users the specialized part is the schema shape (daily-grain facts + immortal aggregates), not the engine; a separate store would cost ops burden and lose joinability against `player`/`membership`/referral data, which is where the marketing questions actually live.

## Tables

| Table | Grain | Retention | Purpose |
|---|---|---|---|
| `player_activity_day` | one row per player per UTC day | 24 months (prune cron) | raw presence fact; everything derives from it |
| `activity_daily_summary` | one row per day × locale | forever | aggregates charts/decisions read; no personal data, survives GDPR deletions and pruning |

`player_activity_day`: `player_id` FK `ON DELETE CASCADE` (GDPR — deleting a player takes their trail), `day` date, `first_seen_at`, unique `(player_id, day)`, index on `(day)`.

`activity_daily_summary`: `day`, `locale` (`'unknown'` when the player has none — never NULL so the unique `(day, locale)` constraint bites), `active_players`, `active_members`, `new_registrations`, `computed_at`. Totals = SUM over locales. New segment dimensions can be added later and backfilled from the raw table while it still holds the window.

## Write path

`PlayerActivitySubscriber` on **`kernel.terminate`** — response already flushed, so zero request latency and no interaction with response cache headers (PR #164 machinery). Only authenticated requests on the **`main` firewall** count (API PAT/OAuth2 traffic is a different population and already tracks `last_used_at`). Legacy Auth0 sessions count too — the handler resolves the player by `userId`, which works for both identity types.

Cost per request is one cache read: a marker in the `player_activity_cache` Redis pool (`sha1(userId)-Ymd`, TTL 26 h) short-circuits everything after the first request of the day. Underneath, `RecordPlayerActivity` (sync, unrouted message) → handler → `INSERT … ON CONFLICT (player_id, day) DO NOTHING` — correct even when Redis lost the marker. The whole subscriber body is try/caught: activity tracking must never break or slow a request.

Day boundaries are **UTC** (deterministic; chart tooling can shift display timezone if ever needed).

## Cron (on the box, daily)

```
# shortly after UTC midnight — roll yesterday into the summary
30 0 * * * docker compose exec web php bin/console myspeedpuzzling:snapshot-activity-summary
# raw-row retention (24 months)
45 0 * * * docker compose exec web php bin/console myspeedpuzzling:prune-player-activity
```

The snapshot is idempotent (replaces the day's rows wholesale) and takes an optional `Y-m-d` argument for backfills/re-runs. The "active membership" condition mirrors `GetPlayerProfile`.

## Consciously out of scope

- **Event-level product analytics** (funnels, page flows, campaign attribution per click) — that is a consent-and-scope question, not a storage question; self-hosting e.g. PostHog means running ClickHouse. Revisit only if questions genuinely outgrow SQL.
- **Charts/dashboard UI** — the tables are the deliverable; a small admin Chart.js page or Metabase-over-Postgres can be added when wanted.
- Complementary signals already owned: registrations (`player.registered_at`), solving times (`tracked_at`), memberships, referrals, newsletter — join, don't duplicate.
