<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use AsyncAws\Core\Configuration;
use AsyncAws\S3\S3Client;
use Monolog\Level;
use Monolog\Processor\PsrLogMessageProcessor;
use Sentry\Monolog\BreadcrumbHandler as SentryBreadcrumbHandler;
use Sentry\Monolog\Handler as SentryMonologHandler;
use Sentry\State\HubInterface;
use SpeedPuzzling\Web\Doctrine\RegexSchemaAssetFilter;
use SpeedPuzzling\Web\Services\Doctrine\FixDoctrineMigrationTableSchema;
use SpeedPuzzling\Web\Services\SentryTracesSampler;
use SpeedPuzzling\Web\Services\StripeWebhookHandler;
use Stripe\StripeClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

return static function (ContainerConfigurator $configurator): void {
    $parameters = $configurator->parameters();

    # https://symfony.com/doc/current/performance.html#dump-the-service-container-into-a-single-file
    $parameters->set('.container.dumper.inline_factories', true);

    $parameters->set('doctrine.orm.enable_lazy_ghost_objects', true);

    $parameters->set('uploadedAssetsBaseUrl', '%env(UPLOADS_BASE_URL)%/original');

    $parameters->set('nginxProxyBaseUrl', '%env(NGINX_PROXY_BASE_URL)%');
    $parameters->set('nginxProxyInternalUrl', '%env(NGINX_PROXY_INTERNAL_URL)%');
    $parameters->set('puzzlePuzzleUsername', '%env(PUZZLE_PUZZLE_USERNAME)%');
    $parameters->set('puzzlePuzzlePassword', '%env(PUZZLE_PUZZLE_PASSWORD)%');

    $parameters->set('stripeApiKey', '%env(STRIPE_API_KEY)%');
    $parameters->set('stripeWebhookSecret', '%env(STRIPE_WEBHOOK_SECRET)%');

    $services = $configurator->services();

    $services->defaults()
        ->autoconfigure()
        ->autowire()
        ->public()
        ->bind('$uploadedAssetsBaseUrl', '%uploadedAssetsBaseUrl%')
        ->bind('$nginxProxyBaseUrl', '%nginxProxyBaseUrl%')
        ->bind('$nginxProxyInternalUrl', '%nginxProxyInternalUrl%')
        ->bind('$puzzlePuzzleUsername', '%puzzlePuzzleUsername%')
        ->bind('$puzzlePuzzlePassword', '%puzzlePuzzlePassword%')
        ->bind('$entrypointsPath', '%kernel.project_dir%/public/build/entrypoints.json');

    $services->set(PdoSessionHandler::class)
        ->args([
            env('DATABASE_URL'),
            [
                // Disable session locking to allow concurrent requests (Live Components, AJAX)
                // Without this, concurrent requests for the same session block each other
                'lock_mode' => PdoSessionHandler::LOCK_NONE,
            ],
        ]);

    $services->set(PsrLogMessageProcessor::class)
        ->tag('monolog.processor');

    // Controllers (excluding Test directory - registered only in dev/test environments)
    $services->load('SpeedPuzzling\\Web\\Controller\\', __DIR__ . '/../src/Controller/**/{*Controller.php}')
        ->exclude([__DIR__ . '/../src/Controller/Test/']);

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
    $services->load('SpeedPuzzling\\Web\\Security\\', __DIR__ . '/../src/Security/**/{*.php}')
        ->exclude([__DIR__ . '/../src/Security/OAuth2User.php']);
    $services->load('SpeedPuzzling\\Web\\EventSubscriber\\', __DIR__ . '/../src/EventSubscriber/**/{*.php}');

    // API Resource Providers
    $services->load('SpeedPuzzling\\Web\\Api\\', __DIR__ . '/../src/Api/**/{*Provider.php}');

    // Components
    $services->load('SpeedPuzzling\\Web\\Component\\', __DIR__ . '/../src/Component/**/{*.php}');

    /** @see https://github.com/doctrine/migrations/issues/1406 */
    $services->set(FixDoctrineMigrationTableSchema::class)
        ->autoconfigure(false)
        ->arg('$dependencyFactory', service('doctrine.migrations.dependency_factory'))
        ->tag('doctrine.event_listener', ['event' => 'postGenerateSchema']);

    // Custom RegexSchemaAssetFilter to avoid DBAL deprecation warning
    // Replaces the built-in schema_filter config with same regex pattern
    $services->set(RegexSchemaAssetFilter::class)
        ->args(['~^(?!tmp_|custom_)~'])
        ->tag('doctrine.dbal.schema_filter');

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

    $services->set(StripeClient::class)
        ->args([
            param('stripeApiKey'),
        ]);

    $services->set(StripeWebhookHandler::class)
        ->args([
            param('stripeWebhookSecret'),
        ]);

    // PSR-18 HTTP Client for Auth0 SDK
    $services->set('psr18.http_client', Psr18Client::class);

    // Sentry Monolog Handler for error reporting
    $services->set(SentryMonologHandler::class)
        ->args([
            service(HubInterface::class),
            Level::Error,
            true,
            true,
        ]);

    // Sentry Breadcrumb Handler for capturing logs as breadcrumbs
    $services->set(SentryBreadcrumbHandler::class)
        ->args([
            service(HubInterface::class),
            Level::Info,
        ]);

    // Sentry Traces Sampler with profiling trigger support
    $services->set(SentryTracesSampler::class)
        ->arg('$profilingSecret', env('PROFILING_TRIGGER_SECRET'))
        ->arg('$defaultTracesSampleRate', env('SENTRY_TRACES_SAMPLE_RATE')->float());

    $services->set('sentry.traces_sampler', \Closure::class)
        ->factory([service(SentryTracesSampler::class), '__invoke']);
};
