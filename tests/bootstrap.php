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
    $pdo = new PDO(
        'pgsql:host=postgres;port=5432;dbname=postgres',
        'postgres',
        'postgres',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Terminate any connections to template and source databases
    $pdo->exec(
        "SELECT pg_terminate_backend(pid) FROM pg_stat_activity
        WHERE datname IN ('speedpuzzling_test_template', 'speedpuzzling_test') AND pid <> pg_backend_pid()"
    );

    // Unmark as template (required before dropping)
    $pdo->exec("UPDATE pg_database SET datistemplate = FALSE WHERE datname = 'speedpuzzling_test_template'");

    // Drop and recreate
    $pdo->exec("DROP DATABASE IF EXISTS speedpuzzling_test_template");
    $pdo->exec("CREATE DATABASE speedpuzzling_test_template TEMPLATE speedpuzzling_test");

    // Mark as template for faster cloning
    $pdo->exec("UPDATE pg_database SET datistemplate = TRUE WHERE datname = 'speedpuzzling_test_template'");
}
