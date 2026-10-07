<?php

declare(strict_types=1);

use SpeedPuzzling\Web\SymfonyApplicationKernel;
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
    . '-search-keys-' . SearchText::VERSION;

// ParaTest (vendor/bin/paratest) runs the suite in several processes at once, each
// with its own TEST_TOKEN (1..N). They take turns here: the first one builds the
// database if it is stale, then every worker gets its own copy of it, cloned from the
// template - DAMA's per-test transactions keep the copies as clean as the original.
$databaseLock = fopen(__DIR__ . '/.database.lock', 'c');
assert($databaseLock !== false);
flock($databaseLock, LOCK_EX);

if (
    TestingDatabaseCaching::isCacheUpToDate($cacheFilePath, $currentDatabaseHash) === false
) {
    bootstrapDatabase($cacheFilePath);
    file_put_contents($cacheFilePath, $currentDatabaseHash);
    createPantherTemplateDatabase();
}

$workerToken = getenv('TEST_TOKEN');

if (is_string($workerToken) && $workerToken !== '') {
    useWorkerDatabase($workerToken);
}

flock($databaseLock, LOCK_UN);
fclose($databaseLock);


function bootstrapDatabase(string $cacheFilePath): void
{
    $kernel = new SymfonyApplicationKernel('test', true);
    $kernel->boot();

    $application = new Application($kernel);
    $application->setAutoExit(false);

    // Always drop and recreate when cache is invalid
    $application->run(new ArrayInput([
        'command' => 'doctrine:database:drop',
        '--if-exists' => 1,
        '--force' => 1,
    ]));

    $application->run(new ArrayInput([
        'command' => 'doctrine:database:create',
    ]));

    // Create PostgreSQL extensions required by queries (from migrations)
    createPostgresExtensions();

    // Faster than running migrations
    $application->run(new ArrayInput([
        'command' => 'doctrine:schema:create',
    ]));

    // Create custom indexes that Doctrine cannot manage
    createCustomIndexes();

    $result = $application->run(new ArrayInput([
        'command' => 'doctrine:fixtures:load',
        '--no-interaction' => 1,
    ]));

    if ($result !== 0) {
        throw new LogicException('Command doctrine:fixtures:load failed');
    }

    $kernel->shutdown();
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

    // Create immutable wrapper for unaccent (required for index expressions)
    $pdo->exec("
        CREATE OR REPLACE FUNCTION immutable_unaccent(text)
        RETURNS text AS \$\$
            SELECT unaccent('unaccent', \$1)
        \$\$ LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
    ");
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

    // Hub "Most active solo players" by month (Version20261003173349)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_pst_finished_at_solo ON puzzle_solving_time (finished_at) WHERE puzzling_type = \'solo\'');

    // Puzzle intelligence recalculation optimization (Version20260331200000)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_pst_intelligence ON puzzle_solving_time (player_id, puzzle_id) WHERE puzzling_type = \'solo\' AND suspicious = false AND seconds_to_solve IS NOT NULL');
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_pst_intelligence_first_attempt ON puzzle_solving_time (puzzle_id, player_id) WHERE first_attempt = true AND puzzling_type = \'solo\' AND suspicious = false AND seconds_to_solve IS NOT NULL');

    // Chat message unread optimization (Version20260212002500)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_chat_message_unread ON chat_message (conversation_id, sender_id) WHERE read_at IS NULL');

    // Case-insensitive unique email for native auth (Version20260724073022)
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS custom_user_account_email_lower ON user_account (lower(email))');

    // Followers lookup on player favorites (Version20260918171659)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_player_favorite_players_gin ON player USING GIN ((favorite_players::jsonb))');

    // Unread notifications badge (Version20260930163000)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_notification_unread ON notification (player_id) WHERE read_at IS NULL');

    // Player search (Version20260930163100)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_player_search_trgm ON player USING GIN (LOWER(name) gin_trgm_ops, LOWER(code) gin_trgm_ops, LOWER(immutable_unaccent(name)) gin_trgm_ops, LOWER(immutable_unaccent(code)) gin_trgm_ops)');

    // Review queue counts in the key menu: hidden and unapproved puzzles (Version20261008100000)
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_puzzle_hidden ON puzzle (id) WHERE hide_until IS NOT NULL OR hide_image_until IS NOT NULL');
    $pdo->exec('CREATE INDEX IF NOT EXISTS custom_puzzle_unapproved ON puzzle (id) INCLUDE (hide_until, hide_image_until) WHERE approved = false');
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
