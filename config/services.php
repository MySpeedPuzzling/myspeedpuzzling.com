<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use AsyncAws\Core\Configuration;
use AsyncAws\S3\S3Client;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Monolog\Level;
use Monolog\Processor\PsrLogMessageProcessor;
use Sentry\Monolog\BreadcrumbHandler as SentryBreadcrumbHandler;
use Sentry\Monolog\ExceptionToSentryIssueHandler;
use Sentry\Monolog\LogToSentryIssueHandler;
use Sentry\State\HubInterface;
use SpeedPuzzling\Web\Doctrine\RegexSchemaAssetFilter;
use SpeedPuzzling\Web\Services\Api\ApiDtoNormalizer;
use SpeedPuzzling\Web\Services\Doctrine\FixDoctrineMigrationTableSchema;
use SpeedPuzzling\Web\Services\SentryTracesSampler;
use SpeedPuzzling\Web\Services\Storage\FailoverS3Adapter;
use SpeedPuzzling\Web\Services\Storage\ObjectStorageHttpClientFactory;
use SpeedPuzzling\Web\Services\Storage\UploadSpool;
use SpeedPuzzling\Web\Services\Storage\UploadSpoolProcessor;
use SpeedPuzzling\Web\Services\StripeWebhookHandler;
use Stripe\StripeClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;
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
    $parameters->set('bounceEmailDomain', '%env(BOUNCE_EMAIL_DOMAIN)%');

    $parameters->set('listmonkApiUrl', '%env(trim:string:LISTMONK_API_URL)%');
    $parameters->set('listmonkApiUser', '%env(trim:string:LISTMONK_API_USER)%');
    $parameters->set('listmonkApiToken', '%env(trim:string:LISTMONK_API_TOKEN)%');

    // WJPF (worldjigsawpuzzle.org) player pairing - shared static token, both directions.
    // Empty token disables the outbound client and the inbound endpoint alike.
    $parameters->set('wjpfApiUrl', '%env(trim:string:WJPF_API_URL)%');
    $parameters->set('wjpfApiToken', '%env(trim:string:WJPF_API_TOKEN)%');

    $parameters->set('auth0Domain', '%env(trim:string:AUTH0_DOMAIN)%');
    $parameters->set('auth0ClientId', '%env(trim:string:AUTH0_CLIENT_ID)%');
    $parameters->set('auth0ClientSecret', '%env(trim:string:AUTH0_CLIENT_SECRET)%');
    $parameters->set('auth0DatabaseConnection', '%env(trim:string:AUTH0_DB_CONNECTION)%');

    // Lifetime of a magic sign-in link. Single source for the firewall's login_link
    // config (config/packages/security.php) and for the copy that tells the user how
    // long the link is good for.
    $parameters->set('signInLinkLifetimeSeconds', 1800);

    // How long after its first use a sign-in link still signs in (second layer
    // against mail-provider link scanners, see SingleUseLoginLinkHandler). The
    // scanner fetch and the reader's click are seconds apart, so a short window
    // covers it while keeping the replay exposure of D18 to that minute.
    $parameters->set('signInLinkReuseGraceSeconds', 60);

    // How long "stay signed in" lasts, sliding. The single source for all four
    // places that have to agree, because a visitor is signed out the moment the
    // shortest of them lapses: the session cookie and the server-side row
    // (config/packages/framework.php), the remember-me cookie
    // (config/packages/security.php), and the PdoSessionHandler row TTL below.
    $parameters->set('loginLifetimeSeconds', 2592000);

    // Failed S3 uploads are spooled here and re-uploaded by the
    // myspeedpuzzling:upload-spooled-files cron. Production mounts a persistent
    // named volume at this path (lily.srv compose.yaml) - without it the spool
    // dies with the container.
    $parameters->set('env(UPLOAD_SPOOL_DIR)', '%kernel.project_dir%/var/upload-spool');

    // Bot-blocker trust cookie secret (BotTrustCookieSigner). The committed
    // .env carries the empty default too; this container-level default is
    // belt-and-braces so a missing env can never 500 the site - empty means
    // the __bb_trust cookie is simply not issued.
    $parameters->set('env(CHALLENGE_COOKIE_SECRET)', '');

    // Social login flags (auth hardening PR 2, docs/features/feature_flags.md).
    // One flag per provider so each flips independently as its console setup
    // completes; SOCIAL_LOGIN_ADMIN_ONLY keeps everything invisible to the
    // public until the whole feature is verified end-to-end in production.
    $parameters->set('socialLoginAdminOnly', '%env(bool:SOCIAL_LOGIN_ADMIN_ONLY)%');
    $parameters->set('socialLoginGoogleEnabled', '%env(bool:SOCIAL_LOGIN_GOOGLE_ENABLED)%');
    $parameters->set('socialLoginFacebookEnabled', '%env(bool:SOCIAL_LOGIN_FACEBOOK_ENABLED)%');
    $parameters->set('socialLoginAppleEnabled', '%env(bool:SOCIAL_LOGIN_APPLE_ENABLED)%');

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
        ->bind('$entrypointsPath', '%kernel.project_dir%/public/build/entrypoints.json')
        ->bind('$bounceEmailDomain', '%bounceEmailDomain%')
        ->bind('$auth0Domain', '%auth0Domain%')
        ->bind('$auth0ClientId', '%auth0ClientId%')
        ->bind('$auth0ClientSecret', '%auth0ClientSecret%')
        ->bind('$auth0DatabaseConnection', '%auth0DatabaseConnection%')
        ->bind('$signInLinkLifetimeSeconds', '%signInLinkLifetimeSeconds%')
        ->bind('$signInLinkReuseGraceSeconds', '%signInLinkReuseGraceSeconds%')
        ->bind('$socialLoginAdminOnly', '%socialLoginAdminOnly%')
        ->bind('$socialLoginGoogleEnabled', '%socialLoginGoogleEnabled%')
        ->bind('$socialLoginFacebookEnabled', '%socialLoginFacebookEnabled%')
        ->bind('$socialLoginAppleEnabled', '%socialLoginAppleEnabled%')
        ->bind('$googleClientId', '%env(trim:string:GOOGLE_CLIENT_ID)%')
        ->bind('$googleClientSecret', '%env(trim:string:GOOGLE_CLIENT_SECRET)%')
        ->bind('$facebookAppId', '%env(trim:string:FACEBOOK_APP_ID)%')
        ->bind('$facebookAppSecret', '%env(trim:string:FACEBOOK_APP_SECRET)%')
        ->bind('$appleClientId', '%env(trim:string:APPLE_CLIENT_ID)%')
        ->bind('$appleTeamId', '%env(trim:string:APPLE_TEAM_ID)%')
        ->bind('$appleKeyId', '%env(trim:string:APPLE_KEY_ID)%')
        ->bind('$applePrivateKey', '%env(trim:string:APPLE_PRIVATE_KEY)%')
        ->bind('$listmonkApiUrl', '%listmonkApiUrl%')
        ->bind('$listmonkApiUser', '%listmonkApiUser%')
        ->bind('$listmonkApiToken', '%listmonkApiToken%')
        ->bind('$wjpfApiUrl', '%wjpfApiUrl%')
        ->bind('$wjpfApiToken', '%wjpfApiToken%');

    $services->set(PdoSessionHandler::class)
        ->args([
            env('DATABASE_URL'),
            [
                // Disable session locking to allow concurrent requests (Live Components, AJAX)
                // Without this, concurrent requests for the same session block each other
                'lock_mode' => PdoSessionHandler::LOCK_NONE,
                // Explicit, because the fallback is ini_get('session.gc_maxlifetime').
                // That ini is only correct while NativeSessionStorage::setOptions() gets
                // to apply it - it returns early if headers are already sent or a session
                // is active, and this app runs FrankenPHP workers. Were that ever to
                // happen, every row written on that request would silently get php.ini's
                // default (commonly 1440 = 24 minutes) and those visitors would be
                // genuinely signed out. Naming the TTL here removes the dependency.
                'ttl' => '%loginLifetimeSeconds%',
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
    $services->load('SpeedPuzzling\\Web\\Services\\', __DIR__ . '/../src/Services/**/{*.php}')
        ->exclude([
            // league provider subclass, constructed by SocialLoginProviders - not a service
            __DIR__ . '/../src/Services/SocialLogin/AppleProviderWithInlineKey.php',
            // value object, not a service
            __DIR__ . '/../src/Services/Storage/SpooledOperation.php',
            // value object built by PuzzleResponseFactory::insightsFor(), not a service
            __DIR__ . '/../src/Services/Api/PuzzleInsightsBatch.php',
        ]);
    $services->load('SpeedPuzzling\\Web\\Query\\', __DIR__ . '/../src/Query/**/{*.php}');
    $services->load('SpeedPuzzling\\Web\\Security\\', __DIR__ . '/../src/Security/**/{*.php}')
        ->exclude([
            __DIR__ . '/../src/Security/OAuth2User.php',
            __DIR__ . '/../src/Security/PatUser.php',
            __DIR__ . '/../src/Security/ApiUser.php',
            __DIR__ . '/../src/Security/SignInLinkPasswordPrompt.php',
            __DIR__ . '/../src/Security/SocialRegistrationRequired.php',
        ]);

    // Single-use magic sign-in links (D18, issue #147): wraps the handler the
    // login_link firewall factory builds for the `main` firewall, so both the
    // issuing side (RequestSignInLinkHandler) and the consuming side (the
    // LoginLinkAuthenticator) go through the consumption bookkeeping.
    $services->set(\SpeedPuzzling\Web\Security\SingleUseLoginLinkHandler::class)
        ->decorate('security.authenticator.login_link_handler.main')
        ->arg('$inner', service('.inner'));
    $services->load('SpeedPuzzling\\Web\\EventSubscriber\\', __DIR__ . '/../src/EventSubscriber/**/{*.php}');

    // Sliding 30-day login. Neither argument is autowirable: the remember-me
    // handler is registered by the firewall factory under a per-firewall string
    // id (there is no RememberMeHandlerInterface alias), and the session options
    // are a container parameter rather than a service.
    $services->set(\SpeedPuzzling\Web\EventSubscriber\SlidingLoginCookiesSubscriber::class)
        ->arg('$rememberMeHandler', service('security.authenticator.remember_me_handler.main'))
        ->arg('$sessionOptions', param('session.storage.options'));

    // API Resource Providers and Processors
    $services->load('SpeedPuzzling\\Web\\Api\\', __DIR__ . '/../src/Api/**/{*Provider.php,*Processor.php}');

    // The nested API DTOs are normalized with the same snake_case converter API Platform
    // uses for the resources themselves (config/packages/api_platform.php)
    $services->set(ApiDtoNormalizer::class)
        ->arg('$nameConverter', service('serializer.name_converter.camel_case_to_snake_case'));

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

    // Short timeouts plus one immediate retry: a slow Hetzner answer costs a
    // few seconds, a dead object storage still degrades a request by seconds,
    // not minutes - and the upload spool catches what the retry does not.
    $services->set('app.storage.s3_http_client', HttpClientInterface::class)
        ->factory([ObjectStorageHttpClientFactory::class, 'create'])
        ->args([
            service('monolog.logger.object_storage'),
            null, // own transport - autowiring would hand it the framework http_client
        ]);

    // AsyncAws logs every failed request at error level before it throws. The
    // caller decides what the failure means (a spooled upload, a missing file,
    // a 404 page), so these go to their own channel: kept in the logs for the
    // request URL they carry, kept out of Sentry (prod/monolog.php).
    $services->set(S3Client::class)
        ->args([
            '$configuration' => [
                Configuration::OPTION_REGION => env('S3_REGION'),
                Configuration::OPTION_ENDPOINT => env('S3_ENDPOINT'),
                Configuration::OPTION_ACCESS_KEY_ID => env('S3_ACCESS_KEY'),
                Configuration::OPTION_SECRET_ACCESS_KEY => env('S3_SECRET_KEY'),
                Configuration::OPTION_PATH_STYLE_ENDPOINT => true,
            ],
            '$httpClient' => service('app.storage.s3_http_client'),
            '$logger' => service('monolog.logger.object_storage'),
        ]);

    // S3 failover stack: oneup's `minio` filesystem uses FailoverS3Adapter
    // (config/packages/oneup_flysystem.php), which wraps the raw S3 adapter
    // with a local spool. The processor deliberately gets the raw adapter -
    // retries must not re-spool in a loop. Tests swap the two `app.storage.*`
    // adapter services for in-memory doubles (config/services_test.php).
    $services->set('app.storage.s3_adapter', AsyncAwsS3Adapter::class)
        ->args([
            service(S3Client::class),
            env('S3_BUCKET'),
        ]);

    $services->set('app.storage.spool_adapter', LocalFilesystemAdapter::class)
        ->args([
            env('UPLOAD_SPOOL_DIR'),
            null,
            \LOCK_EX,
            LocalFilesystemAdapter::DISALLOW_LINKS,
            null,
            true, // lazyRootCreation: dev/test must not require the dir upfront
        ]);

    $services->set(UploadSpool::class)
        ->arg('$spoolAdapter', service('app.storage.spool_adapter'));

    $services->set(FailoverS3Adapter::class)
        ->arg('$inner', service('app.storage.s3_adapter'));

    $services->set(UploadSpoolProcessor::class)
        ->arg('$s3Adapter', service('app.storage.s3_adapter'));

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

    // Dedicated Guzzle client for the league social-login providers: a named
    // service so tests can swap in a MockHandler-backed client and no real
    // provider HTTP ever happens in the test suite
    $services->set('social_login.http_client', \GuzzleHttp\Client::class);

    $services->set(\SpeedPuzzling\Web\Services\SocialLogin\SocialLoginProviders::class)
        ->arg('$httpClient', service('social_login.http_client'));

    // The deprecated Sentry\Monolog\Handler (removed in sentry/sentry 5.0) was split
    // in two, and both are needed. Both start at Warning: warnings are problems we want
    // to see too. This one captures log messages - and deliberately SKIPS every record
    // carrying an 'exception' in its context...
    $services->set(LogToSentryIssueHandler::class)
        ->args([
            service(HubInterface::class),
            Level::Warning,
            true, // bubble
            true, // fillExtraContext
        ]);

    // ...which is exactly what this one captures: uncaught exceptions (Symfony logs
    // them with the exception attached) and every `'exception' => $e` log call. With
    // only the handler above, none of them reached Sentry from 2026-07-12 until this
    // was added.
    $services->set(ExceptionToSentryIssueHandler::class)
        ->args([
            service(HubInterface::class),
            Level::Warning,
            true, // bubble
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
