# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

### Development
- `docker compose up` - Start the full development environment
- `npm run dev` - Build frontend assets for development
- `npm run watch` - Watch and rebuild frontend assets on changes
- `npm run build` - Build production frontend assets

### Testing & Quality
- `vendor/bin/phpunit` - Run PHP unit tests
- `composer run phpstan` - Run PHPStan static analysis (max level)
- `composer run cs` - Check coding standards with PHPCS
- `composer run cs-fix` - Fix coding standards with PHPCBF
- `php bin/console doctrine:migrations:migrate` - Run database migrations
- `php bin/console cache:warmup` - Warmup cache, compile container

### Database
- Database runs in Docker on port 5432 (postgres/postgres)
- Adminer available at localhost:8000
- Migrations are in `migrations/` directory

## Architecture

### Tech Stack
- **Backend**: Symfony 7 with PHP 8.3+
- **Database**: PostgreSQL with Doctrine ORM
- **Frontend**: Symfony UX (Stimulus, Turbo, Live Components), Bootstrap 5, Chart.js
- **Assets**: Webpack Encore with Sass/SCSS
- **Authentication**: Auth0 integration
- **File Storage**: S3-compatible (MinIO in development)
- **Payments**: Stripe integration
- **Containerization**: Docker with custom base image

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

### Notable Features
- **Puzzle Time Tracking**: Sophisticated stopwatch with pause/resume and verification
- **Competition Management**: WJPC (World Jigsaw Puzzle Championship) integration
- **Statistics & Charts**: Detailed analytics with Chart.js visualizations
- **Social Features**: Player favorites, collections, and activity feeds
- **Premium Membership**: Stripe-powered subscription management
- **Multi-language**: Czech and English translations

### Turbo Configuration
- **IMPORTANT**: Turbo is globally disabled via `data-turbo="false"` on the `<html>` element in `base.html.twig`
- To use Turbo on specific links or forms, you MUST explicitly enable it with `data-turbo="true"`
- Example for Turbo Frame links: `<a href="..." data-turbo="true" data-turbo-frame="modal-frame">`
- Example for forms: `<form ... data-turbo="true" data-turbo-frame="modal-frame">`
- See `.claude/symfony-ux-hotwire-architecture-guide.md` for modal architecture patterns

- When generating migrations for example or running any other commands that needs to run in the PHP environment, ALWAYS run them in the running docker container prefixed with `docker compose exec web` to make sure it runs in PHP docker container.
- When running commands for Javascript environment, ALWAYS run them in the running docker container prefixed with `docker compose exec js-watch` to make sure it runs in javascript docker container.
- For database structure, analyse Doctrine ORM entities - it represents the database structure
- After changing PHP code ALWAYS run checks to make sure everything works: `docker compose exec web composer run phpstan`, `docker compose exec web composer run cs-fix`, `docker compose exec web vendor/bin/phpunit`, `docker compose exec web doctrine:schema:validate`, `docker compose exec web cache:warmup`.
- When renaming database tables (in doctrine migrations), always make sure to go through the raw SQL Queries (in directory `src/Query/`) and if the table was renamed, update the queries.
- Never run migrations "doctrine:migrations:migrate" yourself - leave it to me or ask explicitely
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