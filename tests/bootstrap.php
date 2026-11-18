<?php

declare(strict_types=1);

use SpeedPuzzling\Web\SymfonyApplicationKernel;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingDatabaseCaching;
use SpeedPuzzling\Web\Value\SearchText;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\ErrorHandler\ErrorHandler;

require_once __DIR__ . '/../vendor/autoload.php';

set_exception_handler([new ErrorHandler(), 'handleException']);

$_ENV['APP_ENV'] = 'test';
(new Dotenv())->loadEnv(__DIR__ . '/../.env');

$cacheFilePath = __DIR__ . '/.database.cache';
$currentDatabaseHash = TestingDatabaseCaching::calculateDirectoriesHash(
    __DIR__ . '/../migrations',
    __DIR__ . '/DataFixtures',
    // The extensions and custom indexes it creates
    __FILE__,
)
    // The fixtures' search keys are built by the code: a new key format (SearchText::VERSION) needs them built again
    . '-search-keys-' . SearchText::VERSION
    // The fixtures take their dates from the frozen clock: a moved instant needs them built again
    . '-now-' . TestClock::FROZEN_NOW;

// The process that builds the database (buildDatabaseInItsOwnProcess() below): it does that and nothing else
if (getenv('MSP_BUILD_TEST_DATABASE') === '1') {
    // A failed build must end this process with an error. The exception handler above only prints an uncaught
    // exception - the process still ended with 0, the cache file was written and the suite ran on a half-built
    // database without a word (2026-11-16: Postgres 18 refused a custom index, no fixture was loaded)
    try {
        bootstrapDatabase($cacheFilePath);
        createPantherTemplateDatabase();
    } catch (Throwable $exception) {
        fwrite(STDERR, sprintf(
            "\nBuilding the test database failed - %s: %s\n%s\n",
            $exception::class,
            $exception->getMessage(),
            $exception->getTraceAsString(),
        ));

        exit(1);
    }

    return;
}

// ParaTest (vendor/bin/paratest) runs the suite in several processes at once, each
// with its own TEST_TOKEN (1..N). They take turns here: the first one builds the
// database if it is stale, then every worker gets its own copy of it, cloned from the
// template - DAMA's per-test transactions keep the copies as clean as the original.
$databaseLock = fopen(__DIR__ . '/.database.lock', 'c');
assert($databaseLock !== false);
flock($databaseLock, LOCK_EX);

if (
    TestingDatabaseCaching::isCacheUpToDate($cacheFilePath, $currentDatabaseHash) === false
    // The cache file knows what the database was built from, not whether the server still has it: another server or
    // an empty volume (the move to Postgres 18) left it "up to date" with no database behind it
    || testDatabaseIsMissing()
) {
    buildDatabaseInItsOwnProcess();
    file_put_contents($cacheFilePath, $currentDatabaseHash);
}

$workerToken = getenv('TEST_TOKEN');

if (is_string($workerToken) && $workerToken !== '') {
    useWorkerDatabase($workerToken);
}

flock($databaseLock, LOCK_UN);
fclose($databaseLock);


/**
 * Building the database boots a debug kernel on a cold cache, creates the schema and loads every fixture - and
 * leaves about 200 MB behind in the process that did it (measured 2026-11-15: 210 MB after the kernel's shutdown, 169
 * after collecting cycles; loaded classes stay). In the process that then loads the whole suite with every data
 * provider's data - ParaTest's main process on a fresh CI runner - that was the difference between fitting into the
 * memory limit and not: 523 MB of 512. So it runs in a process of its own, which ends with what it kept.
 */
function buildDatabaseInItsOwnProcess(): void
{
    putenv('MSP_BUILD_TEST_DATABASE=1');

    try {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__), $exitCode);
    } finally {
        putenv('MSP_BUILD_TEST_DATABASE');
    }

    if ($exitCode !== 0) {
        throw new LogicException(sprintf('Building the test database failed (exit code %d).', $exitCode));
    }
}

/**
 * True only when the server answers and does not have the test database. A server that cannot be reached says
 * nothing: tests that need no database keep running without one, as before.
 */
function testDatabaseIsMissing(): bool
{
    $dbConfig = parseDatabaseUrl();

    try {
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=postgres', $dbConfig['host'], $dbConfig['port']),
            $dbConfig['user'],
            $dbConfig['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3],
        );

        $statement = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :name');
        $statement->execute(['name' => $dbConfig['dbname']]);

        return $statement->fetchColumn() === false;
    } catch (PDOException) {
        return false;
    }
}

/**
 * A console command prints its own error and returns a code - which nobody looked at for the first three steps
 *
 * @param array<string, int|string> $input
 */
function runConsoleCommandOrFail(Application $application, array $input): void
{
    $result = $application->run(new ArrayInput($input));

    if ($result !== 0) {
        throw new LogicException(sprintf('Command %s failed (exit code %d)', $input['command'], $result));
    }
}

function bootstrapDatabase(string $cacheFilePath): void
{
    $kernel = new SymfonyApplicationKernel('test', true);
    $kernel->boot();

    $application = new Application($kernel);
    $application->setAutoExit(false);

    // Always drop and recreate when cache is invalid
    runConsoleCommandOrFail($application, [
        'command' => 'doctrine:database:drop',
        '--if-exists' => 1,
        '--force' => 1,
    ]);

    runConsoleCommandOrFail($application, [
        'command' => 'doctrine:database:create',
    ]);

    // Create PostgreSQL extensions required by queries (from migrations)
    createPostgresExtensions();

    // Faster than running migrations
    runConsoleCommandOrFail($application, [
        'command' => 'doctrine:schema:create',
    ]);

    // Create custom indexes that Doctrine cannot manage
    createCustomIndexes();

    $result = $application->run(new ArrayInput([
        'command' => 'doctrine:fixtures:load',
        '--no-interaction' => 1,
    ]));

    if ($result !== 0) {
        throw new LogicException('Command doctrine:fixtures:load failed');
    }

    // Production has a built pace history (docs/features/puzzle-intelligence/README.md "Pace history"): its rows
    // and the marker that lets the incremental recalculation compute difficulties from it.
    // PaceHistoryRolloutTest deletes both to test the state right after the deploy
    $result = $application->run(new ArrayInput([
        'command' => 'myspeedpuzzling:backfill-pace-history',
    ]));

    if ($result !== 0) {
        throw new LogicException('Command myspeedpuzzling:backfill-pace-history failed');
    }

    recalculateIncrementallyWithPaceHistory($kernel);

    $kernel->shutdown();
}

/**
 * The fixtures' solves ran the incremental insights recalculation while they were loaded - before the
 * pace history existed, so it kept no difficulty (PaceHistoryReadiness). Run it once more per solo
 * time now that the history is built: the fixture database is what production looks like after
 * those adds.
 */
function recalculateIncrementallyWithPaceHistory(SymfonyApplicationKernel $kernel): void
{
    $container = $kernel->getContainer()->get('test.service_container');
    assert($container instanceof Symfony\Component\DependencyInjection\ContainerInterface);

    $connection = $container->get(Doctrine\DBAL\Connection::class);
    assert($connection instanceof Doctrine\DBAL\Connection);
    $handler = $container->get(SpeedPuzzling\Web\MessageHandler\RecalculateIncrementalPuzzleIntelligenceOnSolvingTimeChange::class);
    assert($handler instanceof SpeedPuzzling\Web\MessageHandler\RecalculateIncrementalPuzzleIntelligenceOnSolvingTimeChange);

    /** @var list<array{id: string, puzzle_id: string}> $times */
    $times = $connection->fetchAllAssociative(
        "SELECT id, puzzle_id FROM puzzle_solving_time WHERE puzzling_type = 'solo' AND seconds_to_solve IS NOT NULL ORDER BY tracked_at, id",
    );

    foreach ($times as $time) {
        $handler(new SpeedPuzzling\Web\Events\PuzzleSolvingTimeModified(
            Ramsey\Uuid\Uuid::fromString($time['id']),
            Ramsey\Uuid\Uuid::fromString($time['puzzle_id']),
        ));
    }
}

function createPantherTemplateDatabase(): void
{
    $dbConfig = parseDatabaseUrl();
    $sourceDb = $dbConfig['dbname'];
    $templateDb = $sourceDb . '_template';

    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=postgres',
        $dbConfig['host'],
        $dbConfig['port']
    );

    $pdo = new PDO(
        $dsn,
        $dbConfig['user'],
        $dbConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Terminate any connections to template and source databases
    $pdo->exec(
        "SELECT pg_terminate_backend(pid) FROM pg_stat_activity
        WHERE datname IN ('$templateDb', '$sourceDb') AND pid <> pg_backend_pid()"
    );

    // Unmark as template (required before dropping)
    $pdo->exec("UPDATE pg_database SET datistemplate = FALSE WHERE datname = '$templateDb'");

    // Drop and recreate
    $pdo->exec("DROP DATABASE IF EXISTS \"$templateDb\"");
    $pdo->exec("CREATE DATABASE \"$templateDb\" TEMPLATE \"$sourceDb\"");

    // Mark as template for faster cloning
    $pdo->exec("UPDATE pg_database SET datistemplate = TRUE WHERE datname = '$templateDb'");
}

/**
 * Points this worker process at its own database, a fresh clone of the template
 * (`<dbname>_<token>`). Cloned on every run, so a rebuilt template can never leave a
 * worker on stale data.
 */
function useWorkerDatabase(string $token): void
{
    if (preg_match('/^\d+$/', $token) !== 1) {
        throw new LogicException(sprintf('Unexpected TEST_TOKEN "%s"', $token));
    }

    $dbConfig = parseDatabaseUrl();
    $templateDb = $dbConfig['dbname'] . '_template';
    $workerDb = $dbConfig['dbname'] . '_' . $token;

    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%d;dbname=postgres', $dbConfig['host'], $dbConfig['port']),
        $dbConfig['user'],
        $dbConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $templateExists = $pdo->query("SELECT 1 FROM pg_database WHERE datname = '$templateDb'")?->fetchColumn();

    if ($templateExists === false) {
        createPantherTemplateDatabase();
    }

    $pdo->exec("DROP DATABASE IF EXISTS \"$workerDb\" WITH (FORCE)");
    $pdo->exec("CREATE DATABASE \"$workerDb\" TEMPLATE \"$templateDb\"");

    $databaseUrl = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL');
    assert(is_string($databaseUrl));
    $workerDatabaseUrl = preg_replace(
        '~/' . preg_quote($dbConfig['dbname'], '~') . '(?=\?|$)~',
        '/' . $workerDb,
        $databaseUrl,
        1,
    );
    assert(is_string($workerDatabaseUrl) && $workerDatabaseUrl !== $databaseUrl);

    $_ENV['DATABASE_URL'] = $workerDatabaseUrl;
    $_SERVER['DATABASE_URL'] = $workerDatabaseUrl;
    putenv('DATABASE_URL=' . $workerDatabaseUrl);
}

function createPostgresExtensions(): void
{
    $dbConfig = parseDatabaseUrl();

    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s',
        $dbConfig['host'],
        $dbConfig['port'],
        $dbConfig['dbname']
    );

    $pdo = new PDO(
        $dsn,
        $dbConfig['user'],
        $dbConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Create extensions required by application queries (mirrors migrations)
    $pdo->exec('CREATE EXTENSION IF NOT EXISTS unaccent');
    $pdo->exec('CREATE EXTENSION IF NOT EXISTS pg_trgm');

    // Create immutable wrapper for unaccent (required for index expressions) - schema-qualified like the migrations'
    // (Version20260726081804): from Postgres 17 on, CREATE INDEX runs it with a restricted search_path
    $pdo->exec("
        CREATE OR REPLACE FUNCTION immutable_unaccent(text)
        RETURNS text AS \$\$
            SELECT public.unaccent('public.unaccent'::regdictionary, \$1)
        \$\$ LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
    ");

    // gen_uuid_v7(): time-ordered ids for the rows SQL creates itself (Version20261116160000). Here, before the schema
    // and the fixtures, so whatever they run can call it. Word for word the migration's - GenUuidV7FunctionTest compares them
    $pdo->exec(<<<'SQL'
CREATE OR REPLACE FUNCTION gen_uuid_v7() RETURNS uuid
LANGUAGE sql VOLATILE PARALLEL SAFE
AS $$
    SELECT encode(
        set_bit(
            set_bit(
                overlay(
                    uuid_send(gen_random_uuid())
                    PLACING substring(int8send(floor(extract(epoch FROM clock_timestamp()) * 1000)::bigint) FROM 3)
                    FROM 1 FOR 6
                ),
                52, 1
            ),
            53, 1
        ),
        'hex'
    )::uuid
$$
SQL);
}

function createCustomIndexes(): void
{
    $dbConfig = parseDatabaseUrl();

    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s',
        $dbConfig['host'],
        $dbConfig['port'],
        $dbConfig['dbname']
    );

    $pdo = new PDO(
        $dsn,
        $dbConfig['user'],
        $dbConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Custom indexes from migrations that Doctrine cannot manage

    // Similar puzzle titles in the approval queue (Version20260102200000; its other three and the EAN / catalogue
    // number ones of Version20260918131133 dropped in Version20261004235009)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_puzzle_name_trgm ON puzzle USING GIN (name gin_trgm_ops)');

    // Puzzle search keys of every name and code (Version20261004203522)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_puzzle_search_names_trgm ON puzzle USING GIN (search_names gin_trgm_ops)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_puzzle_search_codes_trgm ON puzzle USING GIN (search_codes gin_trgm_ops)');

    // Query optimization composite indexes (Version20260102230000; custom_pst_tracked_at_type dropped in Version20260930163200)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_pst_player_puzzle_type ON puzzle_solving_time (player_id, puzzle_id, puzzling_type)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_pst_type_time_valid ON puzzle_solving_time (puzzling_type, seconds_to_solve) WHERE seconds_to_solve IS NOT NULL AND suspicious = false');
    $pdo->exec("CREATE INDEX IF NOT EXISTS custom_pst_team_puzzlers_gin ON puzzle_solving_time USING GIN ((team::jsonb->'puzzlers') jsonb_path_ops) WHERE team IS NOT NULL");

    // A player's newest times, the profile's Recent activity (Version20261027003000)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_pst_player_tracked_at ON puzzle_solving_time (player_id, tracked_at)');

    // Hub "Most active solo players" by month (Version20261003173349)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_pst_finished_at_solo ON puzzle_solving_time (finished_at) WHERE puzzling_type = \'solo\'');

    // Puzzle intelligence recalculation optimization (Version20260331200000)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_pst_intelligence ON puzzle_solving_time (player_id, puzzle_id) WHERE puzzling_type = \'solo\' AND suspicious = false AND seconds_to_solve IS NOT NULL');
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_pst_intelligence_first_attempt ON puzzle_solving_time (puzzle_id, player_id) WHERE first_attempt = true AND puzzling_type = \'solo\' AND suspicious = false AND seconds_to_solve IS NOT NULL');
    // The insights batch's qualifying first attempts, read in their ranking order (Version20261027010000)
    $pdo->exec("CREATE INDEX IF NOT EXISTS custom_pst_qualifying_first_attempt ON puzzle_solving_time (player_id, puzzle_id, first_attempt DESC, (COALESCE(finished_at, tracked_at)), tracked_at, id) INCLUDE (seconds_to_solve, finished_at) WHERE puzzling_type = 'solo' AND suspicious = false AND seconds_to_solve IS NOT NULL AND unboxed = false");

    // Chat message unread optimization (Version20260212002500)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_chat_message_unread ON chat_message (conversation_id, sender_id) WHERE read_at IS NULL');

    // Case-insensitive unique email for native auth (Version20260724073022)
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS custom_user_account_email_lower ON user_account (lower(email))');

    // Followers lookup on player favorites (Version20260918171659)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_player_favorite_players_gin ON player USING GIN ((favorite_players::jsonb))');

    // Unread notifications badge (Version20260930163000)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_notification_unread ON notification (player_id) WHERE read_at IS NULL');

    // The notification foreign keys that are nearly always NULL: a partial index instead of Doctrine's full one
    // (Version20261117170000). schema:create built the full ones - the migration drops them, so does this
    $notificationPartialIndexes = [
        'actor_player_id' => 'idx_bf5476ca6d9f05f3',
        'target_borrow_request_id' => 'idx_bf5476ca4f71a703',
        'target_change_request_id' => 'idx_bf5476ca96566c4c',
        'target_competition_round_id' => 'idx_bf5476cafd44e32c',
        'target_conversation_id' => 'idx_bf5476ca4ba2e1f5',
        'target_data_import_batch_id' => 'idx_bf5476ca45b56d34',
        'target_data_import_undo_id' => 'idx_bf5476cacacb171d',
        'target_guest_link_request_id' => 'idx_bf5476ca5f704f2',
        'target_listing_request_id' => 'idx_bf5476caf5730683',
        'target_merge_request_id' => 'idx_bf5476cafb320824',
        'target_partner_listing_id' => 'idx_bf5476caff157ae',
        'target_partner_request_id' => 'idx_bf5476ca99ee7211',
        'target_puzzling_team_id' => 'idx_bf5476caa3e76637',
        'target_sold_swapped_item_id' => 'idx_bf5476ca343e61b1',
        'target_transfer_id' => 'idx_bf5476cad44a07a5',
        // Participant linking notifications (Version20261118000200)
        'target_participant_link_request_id' => 'idx_bf5476ca15541f44',
        'target_competition_participant_id' => 'idx_bf5476ca12667544',
    ];

    foreach ($notificationPartialIndexes as $column => $replacedIndex) {
        $pdo->exec(sprintf('CREATE INDEX IF NOT EXISTS custom_notification_fk_%1$s ON notification (%1$s) WHERE %1$s IS NOT NULL', $column));
        $pdo->exec(sprintf('DROP INDEX IF EXISTS %s', $replacedIndex));
    }

    // A relay team's results (Version20261117192407): NULL on every other result - partial instead of Doctrine's full one
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_pst_fk_relay_result_id ON puzzle_solving_time (relay_result_id) WHERE relay_result_id IS NOT NULL');
    $pdo->exec('DROP INDEX IF EXISTS idx_fe83a93c7a17d902');

    // Prefixes of unique indexes, dropped (Version20261117170000): player_rating_snapshot (player_id),
    // solving_time_prediction (time_id)
    $pdo->exec('DROP INDEX IF EXISTS idx_ab6a238999e6f5df');
    $pdo->exec('DROP INDEX IF EXISTS idx_b40d9d315eeadd3b');

    // Player search (Version20260930163100)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_player_search_trgm ON player USING GIN (LOWER(name) gin_trgm_ops, LOWER(code) gin_trgm_ops, LOWER(immutable_unaccent(name)) gin_trgm_ops, LOWER(immutable_unaccent(code)) gin_trgm_ops)');

    // Review queue counts in the key menu: hidden and unapproved puzzles (Version20261008100000)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_puzzle_hidden ON puzzle (id) WHERE hide_until IS NOT NULL OR hide_image_until IS NOT NULL');
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_puzzle_unapproved ON puzzle (id) INCLUDE (hide_until, hide_image_until) WHERE approved = false');
    // The solo Times boards' best-time aggregate + the profile's ranks per board (Version20261011130000, Version20261027020000)
    $pdo->exec("CREATE INDEX IF NOT EXISTS custom_pst_solo_board ON puzzle_solving_time (puzzle_id, player_id) INCLUDE (seconds_to_solve) WHERE puzzling_type = 'solo' AND suspicious = false AND seconds_to_solve > 0");
    // One stored day of the MSP Rating ladder - movement, header chip, "This week" (Version20261020200000)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_rating_snapshot_day ON player_rating_snapshot (pieces_count, snapshot_date) INCLUDE (player_id, elo_rating, elo_rank) WHERE elo_rating IS NOT NULL');
    // The players off the MSP Rating ladder - every rank count on a profile, GetRanking's private list (Version20261110120000)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_player_off_ladder ON player (id) INCLUDE (is_private) WHERE is_private OR ranking_opted_out');
    // Badge uniqueness — partial indexes for tiered vs single-tier badges (Version20260416210601)
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS custom_badge_unique_tiered ON badge (player_id, type, tier) WHERE tier IS NOT NULL');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS custom_badge_unique_single_tier ON badge (player_id, type) WHERE tier IS NULL');

    // Weekly-delta leaderboard window (Version20260713175512)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_xp_entry_weekly_delta ON xp_entry (earned_at, player_id, amount) WHERE in_weekly_delta = true');

    // XP ledger idempotency anchors (Version20260713131333) — player_id is part of the key
    // because every team participant earns entries for the same solve.
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS custom_xp_entry_solve_reason ON xp_entry (player_id, solving_time_id, reason) WHERE solving_time_id IS NOT NULL AND reason != 'solve_compensation'");

    // Partner finder: one open listing per player and event, the open listings of an event (Version20261022172651)
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS custom_partner_listing_one_open ON partner_listing (competition_id, player_id) WHERE closed_at IS NULL');
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_partner_listing_open_by_event ON partner_listing (competition_id) WHERE closed_at IS NULL');

    // Ask to borrow: one waiting ask per asker, owner and puzzle; the asker's open asks on their wishlist (Version20261026154954)
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS custom_borrow_item_one_waiting ON borrow_request_item (asker_id, owner_id, puzzle_id) WHERE status = 'waiting'");
    $pdo->exec("CREATE INDEX IF NOT EXISTS custom_borrow_item_asker_open ON borrow_request_item (asker_id, puzzle_id) WHERE status IN ('waiting', 'yes')");

    // Sell/swap requests: one open ask per buyer and listing (Version20261027093158)
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS custom_listing_request_item_one_open ON listing_request_item (buyer_id, listing_id) WHERE status IN ('waiting', 'yes')");

    // One pending time proposal per round entry (Version20261117044411, competition toolkit P1)
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS custom_time_proposal_pending_solo ON competition_time_proposal (participant_round_id) WHERE status = 'pending'");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS custom_time_proposal_pending_team ON competition_time_proposal (team_id) WHERE status = 'pending'");
}

/**
 * @return array{host: string, port: int, user: string, password: string, dbname: string}
 */
function parseDatabaseUrl(): array
{
    $databaseUrl = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL');

    if (!is_string($databaseUrl) || $databaseUrl === '') {
        // Fallback for Docker environment
        return [
            'host' => 'postgres',
            'port' => 5432,
            'user' => 'postgres',
            'password' => 'postgres',
            'dbname' => 'speedpuzzling_test',
        ];
    }

    $parsed = parse_url($databaseUrl);
    $dbname = isset($parsed['path']) ? ltrim($parsed['path'], '/') : 'speedpuzzling_test';

    return [
        'host' => $parsed['host'] ?? 'postgres',
        'port' => $parsed['port'] ?? 5432,
        'user' => $parsed['user'] ?? 'postgres',
        'password' => $parsed['pass'] ?? 'postgres',
        'dbname' => $dbname,
    ];
}
