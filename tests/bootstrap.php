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
}


function bootstrapDatabase(string $cacheFilePath): void
{
    $kernel = new SymfonyApplicationKernel('test', true);
    $kernel->boot();

    $application = new Application($kernel);
    $application->setAutoExit(false);

    if (is_file($cacheFilePath)) {
        $application->run(new ArrayInput([
            'command' => 'doctrine:database:drop',
            '--if-exists' => 1,
            '--force' => 1,
        ]));
    }

    $application->run(new ArrayInput([
        'command' => 'doctrine:database:create',
        '--if-not-exists' => 1,
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
