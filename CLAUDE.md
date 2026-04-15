# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

### Development
- `docker compose up` - Start the full development environment
- `npm run dev` - Build frontend assets for development
- `npm run watch` - Watch and rebuild frontend assets on changes
- `npm run build` - Build production frontend assets

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
- **Authentication**: Auth0 integration
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
- **Competitions Management**: `docs/features/competitions-management/` — Community-driven event creation with admin approval, round management, puzzle assignment, table layout planning, and live stopwatch
- **Referral Program**: `docs/features/referral-program.md` — Members earn 10% of referred subscription revenue. No separate entity — `player.referralProgramJoinedAt` + `player.referralProgramSuspended`. Code = player code. Cookie-based + code-input attribution. Payouts per currency, manual admin payout marking

### Feature Flags
Active feature flags are documented in `docs/features/feature_flags.md`. **Always read and update this file** when adding, modifying, or removing feature flags. It tracks which files are gated, what feature each flag belongs to, and when it can be removed.

### API & Authentication
- **Two auth methods:** Personal Access Tokens (PAT) for own data, OAuth2 for third-party apps
- **PAT:** `msp_pat_*` tokens, hashed in DB, `PatAuthenticator` on `api` firewall, `ROLE_PAT`, own data only (`/api/v1/me/*`)
- **OAuth2:** `league/oauth2-server-bundle`, JWT Bearer tokens, scope-based roles
- **Scopes:** `profile:read` (default), `results:read`, `statistics:read`, `collections:read`, `solving-times:write`, `collections:write`
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
- **Current endpoints:** `POST /internal-api/feature-requests/{id}/mark-{in-progress,completed,declined}` with optional `{"githubUrl": "...", "adminComment": "..."}` body.
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
- The service worker uses cache-first for `/build/*`, network-only for HTML navigation (offline fallback only, no caching), and stale-while-revalidate for images

### Turbo Configuration
- **Turbo Drive is globally enabled** for SPA-like forward navigation
- **Snapshot cache is disabled** via `<meta name="turbo-cache-control" content="no-cache">` — no stale content flashes
- **Link prefetch is disabled** via `<meta name="turbo-prefetch" content="false">`
- **Back/forward navigation uses native browser behavior** — restoration visits are intercepted in `app.js` and redirected to `window.location.href` for reliable scroll restoration and iOS swipe-back
- To disable Turbo on specific links or forms, use `data-turbo="false"`
- Turbo Frames still work as before: `<a href="..." data-turbo-frame="modal-frame">`
- **Forms inside the `modal-frame` MUST set an explicit `action:` on `form_start`.** Without it the browser posts to the hosting page URL (not the route that rendered the modal), and the hosting page's response usually includes an empty `<turbo-frame id="modal-frame">` from `base.html.twig` → Turbo swaps the empty frame in → modal silently closes, nothing saved, no error logged. See `.claude/symfony-ux-hotwire-architecture-guide.md` §Gotchas.
- Gate stream responses on the `Turbo-Frame: modal-frame` header, not just `getPreferredFormat() === TurboBundle::STREAM_FORMAT` — Turbo 8 sends stream-accept on every form submission, including full-page ones, so a stream-only check returns the stream for full-page flows too and the redirect is skipped. See Gotchas §2.
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
