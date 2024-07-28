<?php

declare(strict_types=1);

use AsyncAws\Core\Configuration;
use AsyncAws\S3\S3Client;
use Monolog\Processor\PsrLogMessageProcessor;
use SpeedPuzzling\Web\Services\Doctrine\FixDoctrineMigrationTableSchema;
use SpeedPuzzling\Web\Services\SentryApiClient;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;
use function Symfony\Component\DependencyInjection\Loader\Configurator\env;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function(ContainerConfigurator $configurator): void
{
    $parameters = $configurator->parameters();

    # https://symfony.com/doc/current/performance.html#dump-the-service-container-into-a-single-file
    $parameters->set('.container.dumper.inline_factories', true);

    $parameters->set('doctrine.orm.enable_lazy_ghost_objects', true);

    $parameters->set('uploadedAssetsBaseUrl', '%env(UPLOADS_BASE_URL)%/puzzle');

    $parameters->set('sentryAuthToken', '%env(SENTRY_AUTH_TOKEN)%');
    $parameters->set('sentryOrganizationSlug', '%env(SENTRY_ORGANIZATION_SLUG)%');
    $parameters->set('sentryProjectSlug', '%env(SENTRY_PROJECT_SLUG)%');

    $parameters->set('puzzlePuzzleUsername', '%env(PUZZLE_PUZZLE_USERNAME)%');
    $parameters->set('puzzlePuzzlePassword', '%env(PUZZLE_PUZZLE_PASSWORD)%');

    $services = $configurator->services();

    $services->defaults()
        ->autoconfigure()
        ->autowire()
        ->public()
        ->bind('$uploadedAssetsBaseUrl', '%uploadedAssetsBaseUrl%')
        ->bind('$puzzlePuzzleUsername', '%puzzlePuzzleUsername%')
        ->bind('$puzzlePuzzlePassword', '%puzzlePuzzlePassword%');

    $services->set(PdoSessionHandler::class)
        ->args([
            env('DATABASE_URL'),
        ]);

    $services->set(PsrLogMessageProcessor::class)
        ->tag('monolog.processor');

    // Controllers
    $services->load('SpeedPuzzling\\Web\\Controller\\', __DIR__ . '/../src/Controller/{*Controller.php}');

    // Twig extensions
    $services->load('SpeedPuzzling\\Web\\Twig\\', __DIR__ . '/../src/Twig/{*TwigExtension.php}');

    // Repositories
    $services->load('SpeedPuzzling\\Web\\Repository\\', __DIR__ . '/../src/Repository/{*Repository.php}');

    // Form types
    $services->load('SpeedPuzzling\\Web\\FormType\\', __DIR__ . '/../src/FormType/**/{*.php}');

    // Message handlers
    $services->load('SpeedPuzzling\\Web\\MessageHandler\\', __DIR__ . '/../src/MessageHandler/**/{*.php}');

    // Console commands
    $services->load('SpeedPuzzling\\Web\\ConsoleCommands\\', __DIR__ . '/../src/ConsoleCommands/**/{*.php}');

    // Services
    $services->load('SpeedPuzzling\\Web\\Services\\', __DIR__ . '/../src/Services/**/{*.php}');
    $services->load('SpeedPuzzling\\Web\\Query\\', __DIR__ . '/../src/Query/**/{*.php}');

    /** @see https://github.com/doctrine/migrations/issues/1406 */
    $services->set(FixDoctrineMigrationTableSchema::class)
        ->autoconfigure(false)
        ->arg('$dependencyFactory', service('doctrine.migrations.dependency_factory'))
        ->tag('doctrine.event_listener', ['event' => 'postGenerateSchema']);

    $services->set(S3Client::class)
        ->args([
            '$configuration' => [
                Configuration::OPTION_REGION => env('S3_REGION'),
                Configuration::OPTION_ENDPOINT => env('S3_ENDPOINT'),
                Configuration::OPTION_ACCESS_KEY_ID => env('S3_ACCESS_KEY'),
                Configuration::OPTION_SECRET_ACCESS_KEY => env('S3_SECRET_KEY'),
                Configuration::OPTION_PATH_STYLE_ENDPOINT => true,
            ]
        ]);

    $services->set('minio.cache.adapter')
        ->class(Lustmored\Flysystem\Cache\CacheAdapter::class)
        ->args([
            '$adapter' => service('oneup_flysystem.minio_adapter'),
            '$cachePool' => service('cache.flysystem.psr6'),
        ]);

    $services->set(SentryApiClient::class)
        ->args([
            '$authToken' => param('sentryAuthToken'),
            '$organizationSlug' => param('sentryOrganizationSlug'),
            '$projectSlug' => param('sentryProjectSlug'),
        ]);
};
