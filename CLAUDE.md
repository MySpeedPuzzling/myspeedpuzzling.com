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

### Test Fixtures
For working with test fixtures, see `.claude/fixtures.md` for complete documentation of test data structure including:
- Player accounts (membership, admin, private profiles)
- Lent/borrowed puzzles and transfer history
- Collections and collection items
- Sell/swap listings, wishlists
- Competitions and solving times
- Connections between players (favorites, team solving, lending)

### Feature Planning & Brainstorming
Feature design documents and implementation plans are in `docs/features/`. Each feature has its own directory with detailed specifications, entity designs, and step-by-step implementation guides.
- **Marketplace**: `docs/features/marketplace/` — Centralized marketplace, messaging, ratings, shipping settings, admin moderation

### OAuth2 Server
- Powered by `league/oauth2-server-bundle`
- Endpoints: `/oauth2/authorize` (custom controller), `/oauth2/token` (bundle controller)
- API firewall (`^/api/v1/`) uses stateless Bearer token authentication
- Scopes: `profile:read` (default), `results:read`, `statistics:read`, `collections:read`
- Grants: `authorization_code`, `client_credentials`, `refresh_token` (password and implicit disabled)
- PKCE required for public clients only; confidential clients use client secret
- API endpoints: `GET /api/v1/me`, `GET /api/v1/players/{id}/results`, `GET /api/v1/players/{id}/statistics`
- User consent is tracked in `oauth2_user_consent` table (auto-approves previously consented scopes)
- Manage clients: `php bin/console myspeedpuzzling:oauth2:create-client`, `php bin/console myspeedpuzzling:oauth2:list-clients`

### Notable Features
- **Puzzle Time Tracking**: Sophisticated stopwatch with pause/resume and verification
- **Competition Management**: WJPC (World Jigsaw Puzzle Championship) integration
- **Statistics & Charts**: Detailed analytics with Chart.js visualizations
- **Social Features**: Player favorites, collections, and activity feeds
- **Premium Membership**: Stripe-powered subscription management
- **Multi-language**: When adding new features, always do it only in English unless explicitly asked to translate to other locales 

### Service Worker (PWA)
- The service worker is at `public/service-worker.js` with a `CACHE_VERSION` constant
- **Bump `CACHE_VERSION`** when changing: the service worker fetch/caching logic itself, the offline page (`public/offline.html`), or any non-content-hashed static assets served from the same origin
- **No bump needed** for `/build/*` asset changes — those are content-hashed by Webpack Encore and cached by URL, so new builds get new URLs automatically
- The service worker uses cache-first for `/build/*`, network-first for HTML navigation, and stale-while-revalidate for images

### Turbo Configuration
- **IMPORTANT**: Turbo is globally disabled via `data-turbo="false"` on the `<html>` element in `base.html.twig`
- To use Turbo on specific links or forms, you MUST explicitly enable it with `data-turbo="true"`
- Example for Turbo Frame links: `<a href="..." data-turbo="true" data-turbo-frame="modal-frame">`
- Example for forms: `<form ... data-turbo="true" data-turbo-frame="modal-frame">`
- See `.claude/symfony-ux-hotwire-architecture-guide.md` for modal architecture patterns

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
