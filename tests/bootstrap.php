<?php

declare(strict_types=1);

use SpeedPuzzling\Web\SymfonyApplicationKernel;
use SpeedPuzzling\Web\Tests\TestingDatabaseCaching;
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
);

if (
    TestingDatabaseCaching::isCacheUpToDate($cacheFilePath, $currentDatabaseHash) === false
) {
    bootstrapDatabase($cacheFilePath);
    file_put_contents($cacheFilePath, $currentDatabaseHash);
    createPantherTemplateDatabase();
}


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
