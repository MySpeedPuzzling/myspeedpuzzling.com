# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

### Development
- `docker compose up` - Start the full development environment
- `npm run dev` - Build frontend assets for development
- `npm run watch` - Watch and rebuild frontend assets on changes
- `npm run build` - Build production frontend assets

#### Port conflicts (when other projects are running)
If `docker compose up` fails with `Bind for 0.0.0.0:<port> failed: port is already allocated` (common when other local projects occupy the same host ports), do **not** stop the other projects. Every published port is overridable via an env var — find the free ports and start with overrides. Host ports are only for host access; containers talk to each other over the Docker network on the internal ports, so overriding host ports never affects the app or tests.

Override env vars (default in parentheses): `WEB_PORT` (8080), `POSTGRES_PORT` (5432), `MERCURE_PORT` (8082), `ADMINER_PORT` (8000), `IMAGES_CACHE_PORT` (19100), `MINIO_API_PORT` (19000), `MINIO_CONSOLE_PORT` (19001), `MAILER_SMTP_PORT` (1025), `MAILER_UI_PORT` (8025), `CHROME_PORT` (4444), `CHROME_VNC_PORT` (7900), `WEB_TEST_PORT` (8081), `LISTMONK_PORT` (8090).

```bash
# Example: bring the stack up on non-conflicting host ports
POSTGRES_PORT=55432 WEB_PORT=8090 MINIO_API_PORT=29000 MINIO_CONSOLE_PORT=29001 docker compose up -d
```

To just run the quality gates / tests without publishing any host port (avoids conflicts entirely), use a one-off container — it does not bind the service's host ports, and only needs `postgres` reachable on the network:

```bash
POSTGRES_PORT=55432 docker compose up -d --no-deps postgres   # only if postgres isn't already up
docker compose run --rm --no-deps web composer run phpstan
docker compose run --rm --no-deps web vendor/bin/phpunit --exclude-group panther
```

If a service was created but ended up detached from the network (e.g. an aborted `up`), reconnecting can fail on the same host-port bind — recreate it with a free port instead: `POSTGRES_PORT=55432 docker compose up -d --no-deps --force-recreate postgres`.

### Testing & Quality
- `vendor/bin/phpunit --exclude-group panther` - Run PHP unit tests (excluding slow Panther browser tests)
- `vendor/bin/phpunit` - Run all tests including Panther (only when explicitly asked)
- `composer run phpstan` - Run PHPStan static analysis (max level)
- `composer run cs` - Check coding standards with PHPCS
- `composer run cs-fix` - Fix coding standards with PHPCBF
- `php bin/console doctrine:migrations:migrate` - Run database migrations
- `php bin/console cache:warmup` - Warmup cache, compile container

### Database
- Database runs in Docker on port 5432 (postgres/postgres)
- Adminer available at localhost:8000
- Migrations are in `migrations/` directory

### Custom Database Indexes
Indexes that Doctrine cannot manage (e.g., GIN trigram indexes, expression indexes) are handled via a custom `SchemaManagerFactory`:
1. **Named with `custom_` prefix** - e.g., `custom_puzzle_name_trgm`
2. **Created in migrations** - add them manually to migration files
3. **Mirrored in `tests/bootstrap.php`** - The `createPostgresExtensions()` function must create any required extensions/functions
4. **Automatically ignored by Doctrine** - `CustomIndexFilteringSchemaManagerFactory` filters out `custom_*` indexes during schema introspection, so Doctrine will NOT generate `DROP INDEX` statements for them
5. **Registered in `docs/database-indexes.md`** - every custom index with the query it serves and what it measured

Example custom index (from `Version20260102200000.php`):
```sql
-- GIN trigram index for ILIKE with wildcards
CREATE INDEX custom_puzzle_name_trgm ON puzzle USING GIN (name gin_trgm_ops);
```

The `immutable_unaccent()` function is a custom wrapper around PostgreSQL's `unaccent()` that is marked IMMUTABLE (required for index expressions). Use it in queries that should leverage accent-insensitive trigram indexes.

## Architecture

### Tech Stack
- **Backend**: Symfony 8 with PHP 8.5
- **Runtime**: FrankenPHP in Worker mode (long-running PHP processes)
- **Realtime**: Mercure for server-sent events (realtime updates)
- **Database**: PostgreSQL with Doctrine ORM
- **Frontend**: Symfony UX (Stimulus, Turbo, Live Components), Bootstrap 5, Chart.js
- **Assets**: Webpack Encore with Sass/SCSS
- **Authentication**: native Symfony Security on `user_account` (password, e-mailed sign-in link, always-on remember-me, social login behind flags). Auth0 was decommissioned 2026-09-18 — `auth0|…` user ids and `user_account.legacy_auth0` are historical data, not a live integration
- **File Storage**: S3-compatible (MinIO in development)
- **Payments**: Stripe integration
- **Containerization**: Docker with custom base image

### FrankenPHP Worker Mode
Since we use FrankenPHP in worker mode, PHP processes persist between requests. Services that cache data in instance properties **must implement `ResetInterface`** to clear state between requests:

```php
use Symfony\Contracts\Service\ResetInterface;

final class MyService implements ResetInterface
{
    private array $cache = [];

    public function reset(): void
    {
        $this->cache = [];
    }
}
```

Symfony automatically calls `reset()` between requests. Without this, cached data from one user's request could leak to another user's request.

### Application Structure

This is a speed puzzling community website built using **Domain-Driven Design** principles with **CQRS** (Command Query Responsibility Segregation) pattern.

#### Core Entities
- **Player**: Users who solve puzzles, with profiles, statistics, and social features
- **Puzzle**: Jigsaw puzzles with piece counts, manufacturers, and metadata
- **PuzzleSolvingTime**: Records of puzzle completion times with verification
- **Competition**: Organized events with participants and rounds
- **Stopwatch**: Timer functionality for tracking solving sessions

#### CQRS Implementation
- **Commands** (`src/Message/`): Write operations like `AddPuzzleSolvingTime`, `ConnectCompetitionParticipant`
- **Command Handlers** (`src/MessageHandler/`): Process commands and emit domain events
- **Queries** (`src/Query/`): Read operations like `GetPlayerStatistics`, `GetPuzzleOverview`
- **Results** (`src/Results/`): Data transfer objects for query responses

#### Domain Events
- Events are emitted from entities implementing `EntityWithEvents`
- Event handlers notify users about important actions (puzzle solved, membership changes)
- Uses Symfony Messenger for async processing

#### Key Services
- **PuzzlersGrouping**: Handles team puzzle solving functionality
- **MembershipManagement**: Stripe integration for premium features
- **ComputeStatistics**: Calculates player and puzzle statistics
- **UploaderHelper**: Manages S3 file uploads for puzzle images

#### Frontend Architecture
- **Stimulus Controllers** (`assets/controllers/`): Interactive components (stopwatch, barcode scanner, charts)
- **Live Components** (`src/Component/`): Server-rendered dynamic components
- **Twig Templates** (`templates/`): Server-side rendered views with reusable partials

#### Data Flow
1. User actions trigger controller methods
2. Controllers dispatch commands via Symfony Messenger
3. Command handlers modify entities and emit domain events
4. Event handlers send notifications and update related data
5. Queries fetch read-optimized data for display
6. Live Components provide real-time updates

#### State-Changing Operations Pattern
- **All logic that changes application state MUST go through Symfony Messenger handlers** — controllers and console commands only dispatch messages, they never contain business logic
- **Repositories NEVER call `flush()`** — they only `persist()`. Flush is handled by the `doctrine_transaction` Messenger middleware which wraps each handler in a transaction
- **Never extend Doctrine's `ServiceEntityRepository`/`EntityRepository`** — repositories are plain `readonly` classes wrapping `EntityManagerInterface`. When a third-party bundle expects its own repository contract (e.g. SymfonyCasts' `ResetPasswordRequestRepositoryInterface`), implement the interface methods directly on a plain repository class instead of extending `ServiceEntityRepository` or using the bundle's repository trait
- **Console commands dispatch messages** — the command parses input and dispatches a message, the handler contains the logic. Tests test the handler directly, not the command
- **Use `ClockInterface`** instead of `new \DateTimeImmutable()` — enables deterministic time in tests

### Test Fixtures
For working with test fixtures, see `.claude/fixtures.md` for complete documentation of test data structure including:
- Player accounts (membership, admin, private profiles)
- Lent/borrowed puzzles and transfer history
- Collections and collection items
- Sell/swap listings, wishlists
- Competitions and solving times
- Connections between players (favorites, team solving, lending)

### Performance Optimizations
See `docs/performance-optimizations.md` for details on LCP & CLS optimizations:
- Critical CSS strategy (inline styles for skeleton rendering, `<main>` not hidden)
- Font loading with `display=optional` (no FOUT)
- Dynamic imports for flatpickr and barcode scanner polyfill
- Selective Bootstrap SCSS imports (excluded: offcanvas, carousel, popover, tooltip)
- Skeleton placeholder height alignment for Live Components

### Feature Planning & Brainstorming
Feature design documents and implementation plans are in `docs/features/`. Each feature has its own directory with detailed specifications, entity designs, and step-by-step implementation guides.
- **Marketplace**: `docs/features/marketplace/` — Centralized marketplace, messaging, ratings, shipping settings, admin moderation
- **Hint Dismissing**: `docs/features/hint-dismissing.md` — Dismissable hint banners with `dismiss-hint` Stimulus controller, `HintType` enum, per-user persistence
- **Puzzle Insights**: `docs/features/puzzle-intelligence/` — Puzzle difficulty, player skill tiers, MSP rating, derived metrics
- **API & OAuth2**: `docs/features/api/` — Public REST API (V1), OAuth2 server, Swagger docs, internal APIs, deprecated V0
- **Stripe Payments**: `docs/features/stripe.md` — Stripe integration for premium membership
- **Opt-Out Features**: `docs/features/opt-out.md` — Streak and ranking opt-out for players
- **Editing pair/team times**: `docs/features/group-time-editing.md` — every registered group member may edit a group time, only the tracker may delete (`PuzzleSolvingTime::canBeModifiedBy()`, read side `isEditableBy()`); the group is always assembled around the tracker, never the editor; other members get a `GroupSolvingTimeEdited` notification naming the editor (`notification.actor_player_id`)
- **Round results**: `docs/features/competitions-management/round-results.md` — public ranking per competition round at `/en/events/{slug}/results/{roundSlug}` (+ `/en/series/{s}/{e}/results/{roundSlug}`). A time's round is **derived, never picked**: competition + puzzle + solo/duo/team (`SolvingTimeRoundResolver`), no date check; invariant = one round per category per puzzle per competition (`PuzzleAlreadyInCompetitionRoundCategory`). **Transaction gotcha:** handlers only persist, so time add/edit set `competitionRound` in PHP before flush, while round-side changes record `CompetitionRoundsChanged` → `RoundResultsReconciler` on postFlush (routed `sync`). Earliest time per player/team counts. Round slugs kept on rename; `myspeedpuzzling:backfill-round-slugs` + `myspeedpuzzling:reconcile-round-results` (cron in lily.srv)
- **Competitions Management**: `docs/features/competitions-management/` — Community-driven event creation with admin approval, round management, puzzle assignment, table layout planning, and live stopwatch. Linking solving times to events (the add/edit-time "Competition / event" picker: selectable set = `IsCompetitionPubliclyVisible::SQL_CONDITION` incl. series editions, include-current rule on the edit form, server-side validation, `GetSelectableCompetitions` + `CompetitionChoicesBuilder`) — see README §"Linking solving times to events"
- **Referral Program**: `docs/features/referral-program.md` — Members earn 10% of referred subscription revenue. No separate entity — `player.referralProgramJoinedAt` + `player.referralProgramSuspended`. Code = player code. Cookie-based + code-input attribution. Payouts per currency, manual admin payout marking
- **Activity Analytics**: `docs/features/activity-analytics.md` — daily player presence (`player_activity_day`, UTC days, 24-month prune) + immortal per-locale aggregates (`activity_daily_summary`); written by `PlayerActivitySubscriber` on kernel.terminate with Redis dedup; crons `myspeedpuzzling:snapshot-activity-summary` + `myspeedpuzzling:prune-player-activity` (daily)
- **Auth Hardening**: `docs/features/auth-hardening/README.md` — DB auth audit trail (`auth_audit_log` table, `RecordAuthAuditEvent` via `AuthAuditRecorder` — never breaks login), user-facing `/account/recent-activity` page, 24-month prune cron `myspeedpuzzling:prune-auth-audit-log`, GDPR deletion now removes `UserAccount`. Social login (PR 2): Google/Apple/Facebook via league provider libraries, `oauth_identity` table (D13), per-provider authenticators on `main`, cache-backed OAuth state (`social_login_state_cache` pool — Apple's form_post callback has no session), 5 settled linking rules in `SocialAccountResolver`, rule-4 interstitial `/register/social`, "Connected sign-in methods" on edit-profile, ≥1-sign-in-method invariant in `UnlinkOauthIdentityHandler`; flags `SOCIAL_LOGIN_{GOOGLE,FACEBOOK,APPLE}_ENABLED` (OFF) + `SOCIAL_LOGIN_ADMIN_ONLY` (ON) — see `docs/features/feature_flags.md`
- **Remember me**: always on, no checkbox — sliding 30 days, signature-based on `['email','password']`, stock Symfony listener. Symfony's `RememberMeListener` clears the cookie on *every* `LoginFailureEvent`, so no authenticator on `main` may fail on plain page views (the Auth0 one did until Phase 6 — `RememberMeTest` guards it). A cookie-restored visitor holds a `RememberMeToken`, which `IS_AUTHENTICATED_FULLY` rejects: use `IS_AUTHENTICATED_REMEMBERED`, and `^/admin` asks for `ADMIN_ACCESS`
- **Return URLs**: `docs/features/return-url.md` — `?return=` / `?return_title=` convention, also how post-login redirects work (`LoginEntryPoint` puts the destination in the URL; `SessionFreeExceptionListenerPass` flips `ExceptionListener`'s `$stateless` flag so no `target_path` session row is written; social login carries it in the cache-backed `OauthFlowState`, since Apple's form_post callback has no cookies). **Gotcha:** `AbstractLoginFormAuthenticator::supports()` compares `getLoginUrl()` against the request path, so `getLoginUrl()` must stay bare — put the `?return=` on the failure redirect instead. **Every consumer must validate via `SpeedPuzzling\Web\Value\ReturnUrl::tryFrom()`** (rejects `//`, `/\`, schemes, encoded variants, CRLF) or the Twig `safe_return_url()`; templates propagate values onward unsanitised by design, so validation at the point of *use* is the invariant.
- **WJPF Pairing**: `docs/features/wjpf-pairing/README.md` — maps players to worldjigsawpuzzle.org accounts by e-mail (`wjpf_identity`, one row per player, `not_found` stored as a row so re-runs skip it; `wjpf_id` deliberately non-unique). Outbound `WjpfClient` → their `users_pr.php?accion=wjpf_user`; inbound `POST /api/v0/wjpf-pairing` (token from body *or* query, `idusuario`/`idjugador` both accepted). Backfill via `myspeedpuzzling:sync-wjpf-identities` — **read-only survey by default, `--claim` writes and their side stores it permanently**. Their endpoint echoes the row *before* its UPDATE, so a response never confirms its own write, and their `if (!$fila['MySpeedPuzzlingId'])` guard means a stale mapping can never be corrected remotely. Conflicts still pair locally + log a warning. Manual command, no cron
- **Account deletion**: `docs/features/account-deletion.md` — self-service "Delete my account": edit-profile danger zone → e-mailed split-token link (`account_deletion_request`, 60 min, cascades with `user_account`) → last-chance page (`GET /delete-account/{token}` only *shows* it — mail clients prefetch links, so deletion is the CSRF+checkbox `POST`) → `ConfirmAccountDeletion` runs the existing `DeletePlayer` nested → `/account-deleted`. Signed-in-as-that-account browsers are logged out *before* the delete so the audit row cascades. Ops: `myspeedpuzzling:player:delete <uuid|code|email> [--force]`
- **Newsletter (Listmonk)**: `docs/features/newsletter/README.md` — MySpeedPuzzling is the source of truth, Listmonk mirrors it. 6 per-locale lists. Guests subscribe via footer form (double opt-in, `NewsletterSubscriber` entity); players via `player.newsletterEnabled`. **Cron `*/15 * * * * myspeedpuzzling:sync-newsletter-subscribers`** reconciles both ways: pulls Listmonk unsubscribes (one-click header) into MSP, pushes creates/updates/unsubscribes, and **removes deleted players from Listmonk** (`DeletePlayerHandler` also queues immediate removal). The cron never re-confirms a Listmonk unsubscribe — only explicit user actions do. Campaign template versioned at `docs/features/newsletter/listmonk-campaign-template.html`
- **Ravensburger Puzzle Month**: `docs/ravensburger-puzzle-month.md` — box-printed link `/ravensburger-puzzle-month/{edition}` (302 → puzzle detail, `RavensburgerPuzzleMonthRedirectController`) + `…/qr-code.png` encoding that link; edition → puzzle id map is the constant `RavensburgerPuzzleMonthEditions::PUZZLE_IDS`. Each edition starts as a hidden placeholder puzzle (`approved = true`, `hide_until` + `hide_image_until` far-future, created by SQL on the box); reveal = change request for name/image + clearing both columns. Runbook in the doc, no command by design
- **Community moderators**: `docs/features/community-moderators.md` — non-admin role (`player.moderator_since`, null = none) that reviews puzzle change + merge requests and reaches nothing else under `/admin`. `PuzzleModerationVoter` (`PUZZLE_MODERATION_ACCESS` = admin OR moderator); the access_control rule `^/admin/puzzle-(change|merge)-requests` must stay above `^/admin`. Admins appoint/revoke at `/admin/moderators` (`GrantModeratorRole`/`RevokeModeratorRole`, player picker reused from event maintainers). New moderator-reachable areas get their own capability attribute
- **Player blocklist**: `docs/features/player-blocklist.md` — site-wide "stop seeing this player" (safety feature: the blocked side must never be able to tell). One `user_block` row = *blocker must not see blocked*; `source = self` (profile menu, listed + removable in edit-profile) vs `source = admin` (SQL-only, protects the blocked player, never shown to nor removable by the blocker — runbook in the doc). Read side has no chokepoint: **every `src/Query` statement returning other players' identity embeds `HiddenPlayers::sqlExclude()` / `sqlExcludeTeam()`** (empty string when the viewer blocks nobody → SQL and plans unchanged; filter *inside* ranked subqueries so positions close up; group times survive when the viewer took part). `GetPlayerProfile::byId()` 404s hidden players for all profile pages + `/api/v1/players/{id}`. Guests, cron, async handlers and `/admin` see everyone; async notification handlers check `GetUserBlocks::blockersOf()` instead. Aggregates and bilateral history (lending, sales, ratings) are deliberately unfiltered. Guards: `BlocklistCanaryTest` (add every new player-listing page) + `BlocklistQueryCoverageTest` (allowlist with reasons)
- **Private profile allow list**: `docs/features/private-profile-allow-list.md` — a private player lets chosen players see them (`private_profile_viewer`, owner → viewer, managed at `/en/who-can-see-my-profile`). **`PrivateProfileAccess` is the only code that may unmask a private player**: queries select `sqlIsPrivate('alias')` (or `sqlIsPublic()` in personal lists) instead of raw `is_private`, so `isPrivate` on every result/template/API means *hidden from this viewer* and anything unconverted keeps hiding. Costs no query: ids ride on the viewer's own profile (`revealedPrivatePlayerIds`), API tokens share the blocklist's lookup (`ApiViewerRelations`); bare column = byte-identical SQL for everyone else. Guests/cron/client-credentials reveal nobody; a block in either direction outranks the list; global rankings stay closed to private players for everybody. The public share image follows the player's *own* setting, never the viewer. Guards: `PrivateProfileCanaryTest` (add every new player-listing page), `PrivateProfileQueryCoverageTest` (raw `is_private` needs a listed reason)
- **Puzzle Picker ("What should I solve next?")**: `docs/features/puzzle-picker/README.md` + `implementation-plan.md` — route `puzzle_picker` (`/en/what-to-solve-next`), one public route: signed-in tool + indexable guest landing with a live demo (`noindex, follow` on any query string, canonical/hreflang from base, in `sitemap-static.xml`). Seeded random read model `GetPuzzlePickerSuggestions` (filter → `ORDER BY md5(seed||id)` LIMIT → hydrate; ≤ 75 ms worst case on prod), criteria = URL (`PuzzlePickerCriteria`, members-only filters stripped server-side), free filters (collection/solved/pieces/brand/since/my time/community) + Insights filters for members (difficulty, gap vs prediction, personal time budget). Predictions at list scale = bulk `GetPlayerPredictions` sharing `TimePredictionCalculator` with `GetPlayerPrediction` (never materialised). Collections: "Display" mode `player.collection_display_mode` (off / my times / + predictions, members) via `ChangeCollectionDisplayMode`. Shipped as stacked PRs #189 → #193 → #191 → #192 (2026-08-19) + same-day follow-ups on `main` (intro state with "Surprise me", 5 s drumroll reveal + confetti, seed redirect for signed-in draws, switch-based filter sheet, brand logos, footer link; remembered filters and share button removed) — see `implementation-plan.md` "Follow-ups"

### Feature Flags
Active feature flags are documented in `docs/features/feature_flags.md`. **Always read and update this file** when adding, modifying, or removing feature flags. It tracks which files are gated, what feature each flag belongs to, and when it can be removed.

### API & Authentication
- **Two auth methods:** Personal Access Tokens (PAT) for own data, OAuth2 for third-party apps
- **PAT:** `msp_pat_*` tokens, hashed in DB, `PatAuthenticator` on `api` firewall, `ROLE_PAT`, own data only (`/api/v1/me/*`)
- **OAuth2:** `league/oauth2-server-bundle`, JWT Bearer tokens, scope-based roles
- **Scopes:** `profile:read` (default), `results:read`, `statistics:read`, `collections:read`, `solving-times:write`, `collections:write` — single source of truth is the `OAuth2Scope` enum (feeds the bundle config). Bundle role = `strtoupper('ROLE_OAUTH2_' . scope)` **with punctuation kept** (`ROLE_OAUTH2_SOLVING-TIMES:WRITE`) — use `OAuth2Scope::role()`, don't retype. Write scopes are stripped from `client_credentials` tokens (`OAuth2ClientCredentialsScopeSubscriber`); token responses carry the granted `scope` (`ScopeAwareBearerTokenResponse`); `^/api/v1/me` requires `ROLE_PAT`/`ROLE_OAUTH2_USER`, so a client-credentials token gets 403 there, not a 500
- **Grants:** `authorization_code` (read+write), `client_credentials` (read-only), `refresh_token`
- **"Me" endpoints:** `/api/v1/me/*` — PAT or OAuth2 with user context
- **Player endpoints:** `/api/v1/players/{id}/*` — OAuth2 only
- **Write endpoints:** `POST/PUT /api/v1/me/solving-times`, collection CRUD
- **Collections:** Membership gating — system collection (`default`) accessible to all, custom collections members-only
- **OAuth2 client registration:** Web form → admin approval → credential claim link (one-time display)
- **Audit:** `last_used_at` tracked for both PAT and OAuth2 tokens
- **`ApiUser` interface:** Shared by `PatUser` and `OAuth2User`, used by all providers
- **Fair Use Policy:** Required acceptance for PAT generation and OAuth2 client registration
- **Full docs:** `docs/features/api/README.md`

### Internal Admin API
- **Purpose:** admin-only HTTP API for triggering privileged ops (initially feature-request status transitions) from outside the shell — primary consumer is Claude Code automating ops, curl as fallback.
- **Base path:** `/internal-api/*` — completely separate from the public `/api/v1/*` OAuth2 API, intentionally NOT in Swagger at `/api/docs`.
- **Auth:** single static bearer token via `INTERNAL_API_TOKEN` env var. Header: `Authorization: Bearer $INTERNAL_API_TOKEN`. Closed-by-default — empty env var disables the API entirely.
- **Firewall:** dedicated `internal_api` firewall + `InternalApiAuthenticator`, `ROLE_INTERNAL_API`. No user accounts, no DB tokens.
- **Extensibility:** auth/firewall/access_control cover the whole prefix. New endpoints are pure controller-drops under `src/Controller/InternalApi/` that dispatch a Messenger message and return `204`. No security config changes needed per endpoint.
- **Current endpoints:** `POST /internal-api/feature-requests/{id}/mark-{in-progress,completed,declined}` with optional `{"githubUrl": "...", "adminComment": "..."}` body; `GET /internal-api/puzzle-merge-requests` (review queue, incl. fetchable `imageUrl` per candidate) + `POST /internal-api/puzzle-merge-requests/{id}/{approve,reject}`.
- **Moderation endpoints need a reviewer:** the API has no logged-in user, so `INTERNAL_API_REVIEWER_PLAYER_ID` names the player credited with the decision. Empty = those endpoints 400 and dispatch nothing (the feature-request ones don't need it).
- **Puzzle merges are destructive and audited:** approving deletes the merged puzzles and moves their solving times/collection/wishlist/sell-swap/lending rows onto the survivor. `ApprovePuzzleMergeRequestHandler` therefore writes a `puzzle_merge_audit` row first (full before/after snapshot + the ids of everything migrated or dropped, plus `decisionSource`/`decisionConfidence`/`decisionNote`) — for admin-UI merges too. **A puzzle may legitimately hold several EANs / catalogue numbers as a comma-separated list** (one per edition or region) — the admin UI's comma-joined pre-fill is correct, not corruption. Merges therefore *union* both records' codes (`unionIdentifiers()`, de-duplicated, survivor's first); never reduce a list to one value. It likewise carries over any alternative name, cover image or manufacturer that **only** a to-be-deleted puzzle had.
- **Full docs:** `docs/features/internal-api.md` + OpenAPI spec at `docs/features/internal-api.openapi.yaml`.

### Notable Features
- **Puzzle Time Tracking**: Sophisticated stopwatch with pause/resume and verification
- **Competition Management**: WJPC (World Jigsaw Puzzle Championship) integration
- **Statistics & Charts**: Detailed analytics with Chart.js visualizations
- **Social Features**: Player favorites, collections, and activity feeds
- **Premium Membership**: Stripe-powered subscription management
- **Multi-language**: When adding new features, always do it only in English unless explicitly asked to translate to other locales 

### Puzzle Insights System
- **Batch computation**: All insights metrics (difficulty, skill, rating) are computed every 15 minutes via `myspeedpuzzling:recalculate-puzzle-intelligence` console command, NOT event-driven
- **Services**: All calculation logic is in `src/Services/PuzzleIntelligence/` — `PlayerBaselineCalculator`, `PuzzleDifficultyCalculator`, `PlayerSkillCalculator`, `DerivedMetricsCalculator`, `MspRatingCalculator`, `PuzzleIntelligenceRecalculator` (orchestrator)
- **Entities**: `PlayerBaseline`, `PuzzleDifficulty`, `PlayerSkill`, `PlayerSkillHistory`, `PlayerElo`
- **Queries**: `GetPuzzleDifficulty`, `GetPlayerSkill`, `GetPlayerSkillHistory`, `GetPlayerRatingRanking`, `GetPlayerPrediction`
- **Visibility**: All insights data is members-only except raw median, MSP Rating ladder, and methodology page
- **Design doc**: Full specification at `docs/features/puzzle-intelligence/README.md`
- **Cron**: `*/15 * * * * docker compose exec web php bin/console myspeedpuzzling:recalculate-puzzle-intelligence`
- **First-time setup**: After migration, run `php bin/console myspeedpuzzling:recalculate-puzzle-intelligence`

### Service Worker (PWA)
- The service worker is at `public/service-worker.js` with a `CACHE_VERSION` constant
- **Bump `CACHE_VERSION`** when changing: the service worker fetch/caching logic itself, the offline page (`public/offline.html`), or any non-content-hashed static assets served from the same origin
- **No bump needed** for `/build/*` asset changes — those are content-hashed by Webpack Encore and cached by URL, so new builds get new URLs automatically
- The service worker uses cache-first for `/build/*` (fetches with `cache: 'reload'` and buffers the full body before caching — a truncated or HTTP-cache-poisoned download must never become the permanent copy), network-only for HTML navigation (offline fallback only, no caching), and stale-while-revalidate for images. Requests it has no strategy for are NOT intercepted
- Stale `/build/*` cache entries from previous deploys are pruned automatically (on cache miss, validated against current `entrypoints.json`/`manifest.json`)
- **Asset-failure telemetry + self-heal**: an inline ES5 script in `base.html.twig` reports failed `/build` script/link loads (incl. silent SRI rejections) via `sendBeacon` to `POST /-/asset-load-failure` (`AssetLoadFailureController`; `AssetLoadFailureClassifier` logs only actionable verdicts at warning → Sentry — fresh page missing an asset, corrupt bytes vs SRI, heal gave up — and crawlers, pages older than the 7-day carry-over, heals in progress and browser-side blocking at info; reports are bounded per session and a history-state mark stops reload loops even without sessionStorage) and, once per session, purges cached `/build` entries and reloads. It must stay inline and dependency-free — it runs exactly when the bundles don't
- **Stale-document telemetry + self-heal**: a second inline ES5 script in `base.html.twig` compares `<meta name="msp-rendered-at">` with the server's own `Date` header (HEAD `/service-worker.js`; the visitor's clock only raises suspicion, so a phone running fast reports nothing) on real `navigate`/`reload` page loads under a service worker. A document older than 5 min is reported via `sendBeacon` to `POST /-/stale-document` (`StaleDocumentController`: warning → Sentry, but only `info` for `deliveryType: cache`, i.e. a browser restoring a tab from its HTTP cache) and, once per session, the URL is dropped from CacheStorage and the page reloaded. It exists because the v6 worker answered every Chromium navigation stale-while-revalidate ("I have to refresh twice to see my new time") and nothing server-side could see it. The timestamp must stay in the `<meta>`, never in the script text — Turbo re-runs a head script whose text changed. `tests/ServiceWorkerBehaviourTest.php` executes the real worker under node (`tests/service-worker-harness.js`) and fails if any document request is ever answered from, or written to, a cache
- The Docker image carries recent releases' `/build` assets (`.docker/merge-previous-build.php`) so HTML a browser loaded before this release still resolves its assets. Retention is by **age** (`RETENTION_DAYS`, default 7), not by build count — a burst of deploys must not evict a generation clients still hold. Each image ships a `/build/.carried-assets.json` ledger recording when every carried file went stale; the next build reads it so the clock never resets

### Turbo Configuration
- **Turbo Drive is globally enabled** for SPA-like forward navigation
- **Snapshot cache is disabled** via `<meta name="turbo-cache-control" content="no-cache">` — no stale content flashes
- **Link prefetch is disabled** via `<meta name="turbo-prefetch" content="false">`
- **Back/forward navigation uses native browser behavior** — restoration visits are intercepted in `app.js` and redirected to `window.location.href` for reliable scroll restoration and iOS swipe-back
- To disable Turbo on specific links or forms, use `data-turbo="false"`
- Turbo Frames still work as before: `<a href="..." data-turbo-frame="modal-frame">`
- **Forms inside the `modal-frame` MUST set an explicit `action:` on `form_start`.** Without it the browser posts to the hosting page URL (not the route that rendered the modal), and the hosting page's response usually includes an empty `<turbo-frame id="modal-frame">` from `base.html.twig` → Turbo swaps the empty frame in → modal silently closes, nothing saved, no error logged. See `.claude/symfony-ux-hotwire-architecture-guide.md` §Gotchas.
- Gate stream responses on the `Turbo-Frame: modal-frame` header, not just `getPreferredFormat() === TurboBundle::STREAM_FORMAT` — Turbo 8 sends stream-accept on every form submission, including full-page ones, so a stream-only check returns the stream for full-page flows too and the redirect is skipped. See Gotchas §2.
- **Full-page form POSTs must never answer 200.** Turbo Drive discards a 200 answer to a form submission (only a `console.error`) — no page, no flash, no field error; the visitor sees nothing happen. Redirect on success; re-render with 422. `render()` sets 422 only when handed the `FormInterface` itself (`'form' => $form`), never `$form->createView()`; a valid form re-rendered with a flash needs an explicit `new Response(status: 422)`. Frame submissions (`Turbo-Frame` header) are exempt. `TurboDriveFormResponseSubscriber` logs a warning (→ Sentry) for every such answer a real browser gets
- See `.claude/symfony-ux-hotwire-architecture-guide.md` for modal architecture patterns and the full Gotchas list

- When generating migrations for example or running any other commands that needs to run in the PHP environment, ALWAYS run them in the running docker container prefixed with `docker compose exec web` to make sure it runs in PHP docker container.
- When running commands for Javascript environment, ALWAYS run them in the running docker container prefixed with `docker compose exec js-watch` to make sure it runs in javascript docker container.
- **DO NOT manually rebuild JavaScript assets** in development - the `js-watch` Docker service automatically watches and rebuilds assets when files change.
- For database structure, analyse Doctrine ORM entities - it represents the database structure
- After changing PHP code ALWAYS run checks to make sure everything works: `docker compose exec web composer run phpstan`, `docker compose exec web composer run cs-fix`, `docker compose exec web vendor/bin/phpunit --exclude-group panther`, `docker compose exec web php bin/console doctrine:schema:validate`, `docker compose exec web php bin/console cache:warmup`.
- When renaming database tables (in doctrine migrations), always make sure to go through the raw SQL Queries (in directory `src/Query/`) and if the table was renamed, update the queries.
- Never run migrations "doctrine:migrations:migrate" yourself - leave it to me or ask explicitely
- Never write migrations yourself - always generate them using command, unless explicitely asked to create custom index or something like that, because Doctrine no longer needs comments like `DC2Type:datetime_immutable` - we have newest version of doctrine
- **Always use single action controllers** with `__invoke` method instead of multiple action methods. Create separate controller classes for different routes.
- Always use Uuid::uuid7() to create new id.
- When logging exceptions, always pass the full exception object as `'exception' => $e`, never just the message string. This preserves the stack trace and exception class for Sentry and structured logging.
- A caught `HandlerFailedException` is logged as is (`'exception' => $e`) — `UnwrapMessengerExceptionProcessor` (a global Monolog processor) swaps any Messenger wrapper in a record for the exception the handler actually threw (+ `messenger_message` class), so Sentry and Loki show the real cause instead of grouping every failure as HandlerFailedException. Don't dig out `getPrevious()` just for logging.
- **The log level decides what becomes a Sentry issue: warning and above do.** So `warning` means *a person should look at this* — a degraded-but-handled condition (a Stripe dispute, a corrupt upload-spool entry, a form answered 200). Routine events (a wrong password, a bot, a client's cache hiccup) are `info`. Caveat: `info` reaches Loki only when a warning happens in the same request (fingers_crossed), so a signal to count in Loki but keep out of Sentry needs its own excluded channel, not `info`. Wiring: `config/packages/prod/monolog.php` (both Sentry issue handlers top-level; `messenger`/`php`/`sentry_sdk`/`object_storage` excluded — those reach Sentry by another route, or their caller reports what the failure means), pinned by `tests/SentryMonologHandlersTest.php`. Sentry's DSN caps at 1,000 events/hour, so a flood of one noisy warning cannot crowd out real errors.
- When thrown exception is extending `NotFoundHttpException` or uses `WithHttpStatus` attribute, not need to catch and return response like this:
```
try {
    $puzzle = $this->getPuzzleOverview->byId($puzzleId);
} catch (PuzzleNotFound) {
    return new Response('', Response::HTTP_NOT_FOUND);
}
```
Instead just call `$puzzle = $this->getPuzzleOverview->byId($puzzleId);` and let it bubble.
- To check in twig template that user has active membership, use `{% if logged_user.profile.activeMembership %}` - this is safe when 100% sure that user is logged in. When need to check in that he is logged as well, use `{% if logged_user.profile is not null and logged_user.profile.activeMembership %}`.
