# CLAUDE.md

Guidance for Claude Code in this repository. This file holds only what **every** session needs. Area-specific
knowledge lives in path-scoped rules under `.claude/rules/` that Claude Code loads by itself when you read or edit a
matching file - see "Where knowledge lives" below. Keep this file small (≤ 25,000 characters, `ClaudeInstructionsTest`).

## Commands

### Development
- `docker compose up` - Start the full development environment
- `npm run dev` / `npm run watch` / `npm run build` - frontend assets (but **do not rebuild assets manually** in development - the `js-watch` service watches and rebuilds)
- PHP commands (migrations, console, composer, tests) ALWAYS run in the container: `docker compose exec web …`
- JavaScript commands ALWAYS run in the container: `docker compose exec js-watch …`

#### Port conflicts (when other projects are running)
If `docker compose up` fails with `Bind for 0.0.0.0:<port> failed: port is already allocated`, do **not** stop the other projects. Every published port is overridable via an env var - containers talk to each other on internal ports, so overriding host ports never affects the app or tests.

Override env vars (default in parentheses): `WEB_PORT` (8080), `POSTGRES_PORT` (5432), `MERCURE_PORT` (8082), `ADMINER_PORT` (8000), `IMAGES_CACHE_PORT` (19100), `MINIO_API_PORT` (19000), `MINIO_CONSOLE_PORT` (19001), `MAILER_SMTP_PORT` (1025), `MAILER_UI_PORT` (8025), `CHROME_PORT` (4444), `CHROME_VNC_PORT` (7900), `WEB_TEST_PORT` (8081), `LISTMONK_PORT` (8090), and for the opt-in `livekit` service (compose profile `video`) `LIVEKIT_PORT` (7880), `LIVEKIT_TCP_PORT` (7881), `LIVEKIT_UDP_PORT` (7882/udp - published 1:1, the server advertises it). The opt-in `online-cv` worker (compose profile `cv`) publishes no port on purpose - `ONLINE_CV_PORT` only for a debugging run of it: `docker compose --profile cv run --rm -p 127.0.0.1:${ONLINE_CV_PORT:-19200}:8000 online-cv` (or ask the running one from the web container: `docker compose exec web curl -s http://online-cv:8000/health`).

```bash
# Example: bring the stack up on non-conflicting host ports
POSTGRES_PORT=55432 WEB_PORT=8090 MINIO_API_PORT=29000 MINIO_CONSOLE_PORT=29001 docker compose up -d
```

To just run the quality gates / tests without publishing any host port, use a one-off container (only needs `postgres` reachable on the network):

```bash
POSTGRES_PORT=55432 docker compose up -d --no-deps postgres   # only if postgres isn't already up
docker compose run --rm --no-deps web composer run phpstan
docker compose run --rm --no-deps web vendor/bin/paratest --testsuite "Project Test Suite"
```

If a service was created but ended up detached from the network (e.g. an aborted `up`), reconnecting can fail on the same host-port bind — recreate it with a free port instead: `POSTGRES_PORT=55432 docker compose up -d --no-deps --force-recreate postgres`.

### Testing & Quality
- `vendor/bin/paratest --testsuite "Project Test Suite"` - Run PHP tests (excluding slow Panther browser tests) in parallel, one worker per CPU, each on its own clone of the test database (`speedpuzzling_test_<N>`, see `tests/bootstrap.php`) - what CI runs
- `vendor/bin/phpunit --testsuite "Project Test Suite"` - The same tests serially; use `vendor/bin/phpunit <file>` for a single test file
- `vendor/bin/phpunit` - Run all tests including Panther (only when explicitly asked)
- `composer run phpstan` - PHPStan static analysis (max level)
- `composer run cs` / `composer run cs-fix` - check / fix coding standards
- `php bin/console cache:warmup` - Warmup cache, compile container
- After changing PHP code ALWAYS run checks: `docker compose exec web composer run phpstan`, `docker compose exec web composer run cs-fix`, `docker compose exec web vendor/bin/paratest --testsuite "Project Test Suite"`, `docker compose exec web php bin/console doctrine:schema:validate`, `docker compose exec web php bin/console cache:warmup`.

### Database & migrations
- PostgreSQL in Docker (postgres/postgres), Adminer at localhost:8000, migrations in `migrations/`. Doctrine ORM entities describe the database structure.
- **Never run `doctrine:migrations:migrate` yourself** - leave it to Jan or ask explicitly.
- **Never write migrations yourself** - generate them (`docker compose exec web php bin/console doctrine:migrations:diff`), unless explicitly asked for a custom index or similar (custom `custom_*` indexes: `.claude/rules/database.md`).
- When a migration renames a table, update the raw SQL queries in `src/Query/`.

## Architecture

- **Stack**: Symfony 8, PHP 8.5, FrankenPHP worker mode, PostgreSQL + Doctrine ORM, Mercure, Symfony UX (Stimulus, Turbo, Live Components), Bootstrap 5, Chart.js, Webpack Encore (Sass), S3-compatible storage (MinIO in dev), Stripe, Docker.
- **Authentication**: native Symfony Security on `user_account` (password, e-mailed sign-in link, always-on remember-me, social login: Google, Microsoft, Apple, Facebook - each once its credentials are set). Auth0 was decommissioned 2026-09-18 — `auth0|…` user ids and `user_account.legacy_auth0` are historical data, not a live integration.
- **Domain-Driven Design + CQRS**: commands in `src/Message/`, handlers in `src/MessageHandler/`, queries in `src/Query/` (raw SQL, read-optimized), results/DTOs in `src/Results/`, value objects in `src/Value/`, Live Components in `src/Component/`, Stimulus controllers in `assets/controllers/`. Long-standing services worth knowing: `PuzzlersGrouping` (pair/team input), `ComputeStatistics`, `UploaderHelper` (S3 uploads), `MembershipManagement` (Stripe). Entities implementing `EntityWithEvents` emit domain events, handled via Symfony Messenger (notifications, async work).

### FrankenPHP Worker Mode
PHP processes persist between requests. Services that cache data in instance properties **must implement `ResetInterface`** to clear state between requests - otherwise one user's data leaks into another user's request:

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

### State-Changing Operations Pattern
- **All logic that changes application state MUST go through Symfony Messenger handlers** — controllers and console commands only dispatch messages, they never contain business logic
- **Repositories NEVER call `flush()`** — they only `persist()`. Flush is handled by the `doctrine_transaction` Messenger middleware which wraps each handler in a transaction
- **A handler that waits for a slow external call (seconds) runs outside that transaction**: its message implements `HandledOutsideTransaction` (the bus's `TransactionMiddleware` = `doctrine_transaction` for every other message), the handler only reads and calls, and writes through a message of its own dispatched right after (not routed = handled at once, its own transaction) - `CheckOnlineFinishPhoto` → `RecordOnlineMediaCheck`. A transaction held across an HTTP call keeps the locks of what it read (a deploy's `ALTER TABLE` waits behind it)
- **The write model works with PHP objects** — a handler loads entities through repository methods (`get()`, `findBy…()`), changes them through their public properties or named entity methods, and removes them through a repository `delete()`. No DQL `UPDATE`/`DELETE` and no native SQL in handlers or services; the one exception is a genuine bulk operation over rows that must not be loaded one by one (e.g. `LentPuzzleTransfer` rewrite in `ApprovePuzzleMergeRequestHandler`, prune crons), and it says why in a comment. An `#[Immutable]` property is changed only through a named method on its entity that explains the case (e.g. `PuzzleChangeRequest::proposedManufacturerMergedInto()`)
- **Never extend Doctrine's `ServiceEntityRepository`/`EntityRepository`** — repositories are plain `readonly` classes wrapping `EntityManagerInterface`. When a third-party bundle expects its own repository contract (e.g. SymfonyCasts' `ResetPasswordRequestRepositoryInterface`), implement the interface methods directly on a plain repository class instead of extending `ServiceEntityRepository` or using the bundle's repository trait
- **Console commands dispatch messages** — the command parses input and dispatches a message, the handler contains the logic. Tests test the handler directly, not the command

## Conventions

- **Always use single action controllers** with `__invoke` method instead of multiple action methods. Create separate controller classes for different routes.
- Always use `Uuid::uuid7()` to create new ids; a set-based SQL statement that creates rows itself calls `gen_uuid_v7()`, never `gen_random_uuid()` (`.claude/rules/database.md`).
- An exception extending `NotFoundHttpException` or carrying the `WithHttpStatus` attribute is not caught to build a 404 response - just call `$this->getPuzzleOverview->byId($puzzleId);` and let it bubble.
- When logging exceptions, always pass the full exception object as `'exception' => $e`, never just the message string. A caught `HandlerFailedException` is logged as is — `UnwrapMessengerExceptionProcessor` swaps any Messenger wrapper for the exception the handler actually threw; don't dig out `getPrevious()` just for logging.
- **The log level decides what becomes a Sentry issue: warning and above do.** So `warning` means *a person should look at this* — a degraded-but-handled condition (a Stripe dispute, a corrupt upload-spool entry, a form answered 200). Routine events (a wrong password, a bot, a client's cache hiccup) are `info`. Caveat: `info` reaches Loki only when a warning happens in the same request (fingers_crossed), so a signal to count in Loki but keep out of Sentry needs its own excluded channel, not `info`. Wiring: `config/packages/prod/monolog.php` (both Sentry issue handlers top-level; `messenger`/`php`/`sentry_sdk`/`object_storage` excluded — those reach Sentry by another route, or their caller reports what the failure means), pinned by `tests/SentryMonologHandlersTest.php`. Sentry's DSN caps at 1,000 events/hour.
- **Translations**: build and iterate in English (`messages.en.yml`). Before opening the PR or completing a feature request, add every new translation key to all 6 locales (cs, de, es, fr, ja next to en) - no locale may fall back to English.
- **Membership in Twig**: `{% if logged_user.profile.activeMembership %}` when the user is surely logged in, else `{% if logged_user.profile is not null and logged_user.profile.activeMembership %}`. **Every members-only feature (new, changed, removed or freed) updates `templates/membership/_benefits.html.twig` + its `membership.benefits.items.*` texts in all 6 locales in the same change.** Never list unbuilt features.
- **Return URLs** (`?return=` / `?return_title=`): every consumer validates via `SpeedPuzzling\Web\Value\ReturnUrl::tryFrom()` or the Twig `safe_return_url()` at the point of use (`docs/features/return-url.md`).
- Every value inside `<script type="application/ld+json">` goes through the Twig filter `json_ld`, never `|json_encode|raw` (it escapes `< > & ' "`, so a player-typed `<!--<script>` in a name cannot swallow the page).
- **Feature flags**: always read and update `docs/features/feature_flags.md` when adding, modifying or removing one.
- **Loose ends** of shipped work go to `docs/TODO.md` (a section per topic, checkboxes, link to the feature doc); tick items when they ship and delete empty sections.
- **Production vs sandbox**: "on production" / nothing said = myspeedpuzzling.com (`INTERNAL_API_TOKEN`), "on sandbox" = sandbox.myspeedpuzzling.com (`SANDBOX_INTERNAL_API_TOKEN`) - never mix hosts, tokens or servers (`.claude/rules/features/platform.md`).

### Turbo - the three gotchas that break forms (full section: `.claude/rules/frontend.md`)
- **Forms inside the `modal-frame` MUST set an explicit `action:` on `form_start`**, otherwise the modal silently closes and nothing is saved.
- **Full-page form POSTs must never answer 200** (Turbo Drive discards it): redirect on success, re-render with 422 (`render()` sets 422 only when handed the `FormInterface` itself). Frame submissions are exempt.
- Gate stream responses on the `Turbo-Frame: modal-frame` header, not just on the stream format.

### Read side - before building any page, API answer or e-mail that lists players, results, events or puzzles
Reusing an existing query is not enough: the page itself must keep the cross-feature guarantees - blocked players hidden
(`HiddenPlayers`), private players masked (`PrivateProfileAccess`), suspicious times neither counted nor timed,
tracker-less (imported) results handled, drafts/unapproved events hidden, custom field values never shown - and **a new
surface is added to the matching canary tests** (`BlocklistCanaryTest`, `PrivateProfileCanaryTest`, `DraftCanaryTest`,
`TrackerlessTimeCanaryTest`, `SeriesPickCanaryTest`, `CustomValuesPrivacyCanaryTest`). Details: `.claude/rules/queries.md`.

## Where knowledge lives

**Path-scoped rules** (`.claude/rules/`, YAML `paths:` frontmatter) load automatically the moment you read or edit a matching file. When you plan work in an area before touching its code, **read its rule file on purpose** - it holds the as-built summary of every feature there, with links to the design docs in `docs/features/`.

| Area | Rule file | Covers |
|---|---|---|
| Read side | `.claude/rules/queries.md` | rules every query/listing must keep (blocklist, private profiles, suspicious and tracker-less times, series picks, drafts, custom values, text search) and their guard tests |
| Results | `.claude/rules/features/results.md` | add/edit results, pairs & teams, first tries, duplicates, time verification, photo uploads, result detail, leaderboard chart, placing |
| My data | `.claude/rules/features/my-data.md` | custom fields ("My fields"), spreadsheet / Google Sheets data import, My data pages |
| Events | `.claude/rules/features/events.md` | events page, series/editions, organizations + drafts, competition management, participants sheet, round results, online competitions, partner finder, results import, events via internal API |
| Catalogue | `.claude/rules/features/catalogue.md` | puzzle names/EANs/brand codes, approvals, brands, moderators, barcode scanner, piece filter, SEO, Ravensburger Puzzle Month |
| Insights | `.claude/rules/features/insights.md` | difficulty/skill batch, predictions, MSP rating, Puzzle Picker, difficulty on lists |
| Players | `.claude/rules/features/players.md` | player header, Players page, compare, blocklist, private allow list, time zone, onboarding, notifications, activity feed, hints, modals, karma, XP, footer |
| Marketplace | `.claude/rules/features/marketplace.md` | sell/swap, events marketplace, listing requests, wishlist + borrowing, bulk selection, data export |
| Accounts | `.claude/rules/features/account.md` | auth hardening, sign-in UX, sessions, remember me, return URLs, account deletion, Stripe, membership page, free trial, referrals |
| E-mail | `.claude/rules/features/email.md` | SES, bounces/complaints, newsletter (Listmonk), transactional e-mail templates |
| Platform | `.claude/rules/features/platform.md` | sandbox, activity analytics, WJPF pairing, CI/deploy pipeline, health checks |
| Public API | `.claude/rules/api.md` | API v1, PAT, OAuth2 scopes, API usage statistics |
| Internal API | `.claude/rules/internal-api.md` | `/internal-api/*` admin endpoints (calling it: skill `internal-api`) |
| Frontend | `.claude/rules/frontend.md` | Turbo configuration, Stimulus/Live Components, performance (LCP/CLS) |
| Service worker | `.claude/rules/service-worker.md` | `CACHE_VERSION` rules, caching strategies, asset/stale-document self-heal |
| Database | `.claude/rules/database.md` | custom `custom_*` indexes, `immutable_unaccent()`, `gen_uuid_v7()` for ids created in SQL |
| Translations | `.claude/rules/translations.md` | locales and the words features fixed on purpose (never "suspicious", never "rival", Brand code…) |

Other references (read when needed): `.claude/fixtures.md` (test fixture players, puzzles, collections…), `.claude/symfony-ux-hotwire-architecture-guide.md` (modal patterns + Gotchas), `.claude/postgres-puzzle-query-optimization.md`, `docs/features/` (one design doc per feature), `docs/ci-pipeline.md`, `docs/TODO.md`, `docs/features/feature_flags.md`.

### Keeping instructions lean
- A new or changed feature's as-built summary goes into its area's rule file under `.claude/rules/features/` (and its doc), **never into this file**. Only a rule that every session must know belongs here.
- A new area gets a new rule file with a `paths:` frontmatter matching its code, templates, assets and doc; a rule file without `paths:` would load in every session. `ClaudeInstructionsTest` enforces the budgets (this file ≤ 25,000 characters, each rule ≤ 35,000 - Claude Code truncates on-demand files around 40,000).
- Multi-step procedures belong in a skill (`.claude/skills/`), not in a rule.
