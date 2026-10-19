<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use AsyncAws\Core\Configuration;
use AsyncAws\Core\HttpClient\AwsRetryStrategy;
use AsyncAws\S3\S3Client;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Monolog\Level;
use Monolog\Processor\PsrLogMessageProcessor;
use Sentry\Monolog\BreadcrumbHandler as SentryBreadcrumbHandler;
use Sentry\Monolog\ExceptionToSentryIssueHandler;
use Sentry\Monolog\LogToSentryIssueHandler;
use Sentry\State\HubInterface;
use SpeedPuzzling\Web\Doctrine\RegexSchemaAssetFilter;
use SpeedPuzzling\Web\Services\Api\ApiDtoNormalizer;
use SpeedPuzzling\Web\Services\ApiUsage\ApiUsageCounter;
use SpeedPuzzling\Web\Services\ApiUsage\RedisApiUsageCounter;
use SpeedPuzzling\Web\Services\Doctrine\FixDoctrineMigrationTableSchema;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStorage;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStores;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineParticipantApi;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlinePresence;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineStoreBreaker;
use SpeedPuzzling\Web\Services\OnlineRounds\RedisOnlinePresence;
use SpeedPuzzling\Web\Services\OnlineRounds\S3OnlineMediaStorage;
use SpeedPuzzling\Web\Services\RateLimiting\RedisSlidingWindowLimiterFactory;
use SpeedPuzzling\Web\Services\ResultImageCache;
use SpeedPuzzling\Web\Services\Sentry\OnlineTokenSentryScrubber;
use SpeedPuzzling\Web\Services\Mailer\MailStreamRouting;
use SpeedPuzzling\Web\Services\Mailer\Sns\SnsMessageVerifier;
use SpeedPuzzling\Web\Services\SentryTracesSampler;
use SpeedPuzzling\Web\Services\Session\PostgresSessionHandler;
use SpeedPuzzling\Web\Services\Storage\FailoverS3Adapter;
use SpeedPuzzling\Web\Services\Storage\ObjectStorageHttpClientFactory;
use SpeedPuzzling\Web\Services\Storage\UploadSpool;
use SpeedPuzzling\Web\Services\Storage\UploadSpoolProcessor;
use SpeedPuzzling\Web\Services\StripeWebhookHandler;
use Stripe\StripeClient;
use Symfony\Bridge\Doctrine\SchemaListener\PdoSessionHandlerSchemaListener;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\Mailer\Bridge\Amazon\Transport\SesTransportFactory;
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
    // (config/packages/security.php), and the PostgresSessionHandler row TTL below.
    $parameters->set('loginLifetimeSeconds', 2592000);

    // Failed S3 uploads are spooled here and re-uploaded by the
    // myspeedpuzzling:upload-spooled-files cron. Production mounts a persistent
    // named volume at this path (lily.srv compose.yaml) - without it the spool
    // dies with the container.
    $parameters->set('env(UPLOAD_SPOOL_DIR)', '%kernel.project_dir%/var/upload-spool');

    // Result share images kept for 48 hours (ResultImageCache) - a shared volume in production (lily.srv compose.yaml)
    $parameters->set('env(RESULT_IMAGES_DIR)', '%kernel.project_dir%/var/result-images');

    // Bot-blocker trust cookie secret (BotTrustCookieSigner). The committed
    // .env carries the empty default too; this container-level default is
    // belt-and-braces so a missing env can never 500 the site - empty means
    // the __bb_trust cookie is simply not issued.
    $parameters->set('env(CHALLENGE_COOKIE_SECRET)', '');

    // Pacing of the "Your results" e-mails (docs/features/duplicate-results.md, "Sending"): the cron runs every
    // 5 minutes, 5 e-mails a run, 60 s apart = one e-mail a minute; the day cap is a safety guard only. Env vars,
    // and the cron runs one-off containers, so a change applies from the next run.
    $parameters->set('env(RESULT_REVIEW_EMAILS_PER_RUN)', '5');
    $parameters->set('env(RESULT_REVIEW_EMAILS_PER_DAY)', '1000');
    $parameters->set('env(RESULT_REVIEW_EMAIL_SPACING_SECONDS)', '60');
    $parameters->set('result_review_emails_per_run', '%env(int:RESULT_REVIEW_EMAILS_PER_RUN)%');
    // The Times boards' guest cache (TimesBoardGuestCache) - on; off in .env.test (a query test reads a board, changes
    // the times and reads it again within one kernel)
    $parameters->set('env(TIMES_BOARD_GUEST_CACHE)', '1');
    $parameters->set('result_review_emails_per_day', '%env(int:RESULT_REVIEW_EMAILS_PER_DAY)%');
    $parameters->set('result_review_email_spacing_seconds', '%env(int:RESULT_REVIEW_EMAIL_SPACING_SECONDS)%');

    // Pairs & teams picker rollout (docs/features/feature_flags.md): admins only until flipped to 1.
    $parameters->set('pairsTeamsPickerPublic', '%env(bool:PAIRS_TEAMS_PICKER_PUBLIC)%');

    // Background photo uploads straight to object storage (docs/features/feature_flags.md): the kill switch, on by default
    $parameters->set('directPhotoUploads', '%env(bool:DIRECT_PHOTO_UPLOADS)%');

    // Insights (docs/features/feature_flags.md): the page and its links only while 1, admins always.
    $parameters->set('insightsPublic', '%env(bool:INSIGHTS_PUBLIC)%');

    // Online competitions (docs/features/feature_flags.md): organisers may set up online timing only while 1, admins always.
    $parameters->set('onlineRoundsForOrganisers', '%env(bool:ONLINE_ROUNDS_FOR_ORGANISERS)%');

    // Online competitions' evidence uploads: an empty ONLINE_MEDIA_PUBLIC_ENDPOINT falls back to this (OnlineMediaStores)
    $parameters->set('online_media_s3_endpoint', '%env(S3_ENDPOINT)%');

    // Online competitions' finish detection (docs/features/online-competitions/README.md §5.7, implementation-plan.md
    // WP 2.1): where the participant's page loads MediaPipe and its models from - the one place (OnlineDetectorAssets →
    // the page's config). Pinned versions on their CDNs, never `latest`; the bench pins the same ones
    // (assets/online/hands_up_signal.js DETECTOR_ASSETS) and bin/fetch-online-detector.mjs copies exactly these files -
    // OnlineDetectorAssetsTest keeps all of them equal.
    $parameters->set('onlineDetectorAssets', [
        'tasksVisionUrl' => 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@1.1.0/vision_bundle.mjs',
        'wasmBaseUrl' => 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@1.1.0/wasm',
        'poseModelUrl' => 'https://storage.googleapis.com/mediapipe-models/pose_landmarker/pose_landmarker_lite/float16/1/pose_landmarker_lite.task',
        'gestureModelUrl' => 'https://storage.googleapis.com/mediapipe-models/gesture_recognizer/gesture_recognizer/float16/1/gesture_recognizer.task',
    ]);
    // Our own copy of those files (README §5.7 "Downloads"): empty = the CDNs above (dev, tests); the production image
    // serves them itself and sets /online-detector/<version>-<models> (Dockerfile) - an env on the box still overrides it
    $parameters->set('env(ONLINE_DETECTOR_BASE_URL)', '');
    $parameters->set('onlineDetectorBaseUrl', '%env(trim:string:ONLINE_DETECTOR_BASE_URL)%');

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
        // lily.srv's drain-first rollout creates it in an old container (HealthCheckLivenessController)
        ->bind('$drainMarkerPath', '/tmp/drain')
        ->bind('$bounceEmailDomain', '%bounceEmailDomain%')
        ->bind('$signInLinkLifetimeSeconds', '%signInLinkLifetimeSeconds%')
        ->bind('$signInLinkReuseGraceSeconds', '%signInLinkReuseGraceSeconds%')
        ->bind('$kernelSecret', '%kernel.secret%')
        ->bind('$pairsTeamsPickerPublic', '%pairsTeamsPickerPublic%')
        ->bind('$onlineRoundsForOrganisers', '%onlineRoundsForOrganisers%')
        ->bind('$insightsPublic', '%insightsPublic%')
        ->bind('$resultReviewEmailsPerRun', '%result_review_emails_per_run%')
        ->bind('$resultReviewEmailsPerDay', '%result_review_emails_per_day%')
        ->bind('$resultReviewEmailSpacingSeconds', '%result_review_email_spacing_seconds%')
        ->bind('$googleClientId', '%env(trim:string:GOOGLE_CLIENT_ID)%')
        ->bind('$googleClientSecret', '%env(trim:string:GOOGLE_CLIENT_SECRET)%')
        ->bind('$facebookAppId', '%env(trim:string:FACEBOOK_APP_ID)%')
        ->bind('$facebookAppSecret', '%env(trim:string:FACEBOOK_APP_SECRET)%')
        ->bind('$appleClientId', '%env(trim:string:APPLE_CLIENT_ID)%')
        ->bind('$appleTeamId', '%env(trim:string:APPLE_TEAM_ID)%')
        ->bind('$appleKeyId', '%env(trim:string:APPLE_KEY_ID)%')
        ->bind('$applePrivateKey', '%env(trim:string:APPLE_PRIVATE_KEY)%')
        ->bind('$appleAppId', '%env(trim:string:APPLE_APP_ID)%')
        ->bind('$microsoftClientId', '%env(trim:string:MICROSOFT_CLIENT_ID)%')
        ->bind('$microsoftClientSecret', '%env(trim:string:MICROSOFT_CLIENT_SECRET)%')
        ->bind('$microsoftClientSecretExpiresAt', '%env(trim:string:MICROSOFT_CLIENT_SECRET_EXPIRES_AT)%')
        ->bind('$listmonkApiUrl', '%listmonkApiUrl%')
        ->bind('$listmonkApiUser', '%listmonkApiUser%')
        ->bind('$listmonkApiToken', '%listmonkApiToken%')
        ->bind('$wjpfApiUrl', '%wjpfApiUrl%')
        ->bind('$wjpfApiToken', '%wjpfApiToken%');

    // The `sessions` table keeps the layout Symfony's PdoSessionHandler defines, and
    // that handler's schema listener is what keeps it in Doctrine's schema (without
    // it doctrine:schema:validate fails and migrations:diff drops the table).
    // DoctrineBundle's own listener only looks at session.handler, which is now
    // PostgresSessionHandler, so this one is handed a PdoSessionHandler that exists
    // only to describe the table - it never serves a request, and connects only
    // when a schema command asks it to.
    $services->set('app.sessions_table_schema_listener', PdoSessionHandlerSchemaListener::class)
        ->args([inline_service(PdoSessionHandler::class)->args([env('DATABASE_URL')])])
        ->tag('doctrine.event_listener', ['event' => 'postGenerateSchema']);

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
            // SQL fragment of one typed search, built by the queries - not a service
            __DIR__ . '/../src/Services/PuzzleTextSearch.php',
            // one planning run of ParticipantImportPlanner and its values - not services
            __DIR__ . '/../src/Services/ParticipantImport/Plan/',
            // one planning run of SheetChangesPlanner and its result - not services
            __DIR__ . '/../src/Services/ParticipantsSheet/Plan/',
            // the counters of one suspicious time scan, created per run by its handler - not a service
            __DIR__ . '/../src/Services/SuspiciousTimes/SuspiciousTimeScanTally.php',
            // one key of a rate limit, made by its factory; the factories are set up per limit below (online_rate_limiter.*)
            __DIR__ . '/../src/Services/RateLimiting/',
            // the prediction model's in-memory data of one batch run or evaluation, and the priors' values - not services
            __DIR__ . '/../src/Services/PuzzleIntelligence/Model/FirstAttemptSet.php',
            __DIR__ . '/../src/Services/PuzzleIntelligence/Model/PriorLookup.php',
            __DIR__ . '/../src/Services/PuzzleIntelligence/Model/PredictionEngine.php',
            __DIR__ . '/../src/Services/PuzzleIntelligence/Model/RepeatHistory.php',
            __DIR__ . '/../src/Services/PuzzleIntelligence/Model/EvaluationGroups.php',
            __DIR__ . '/../src/Services/PuzzleIntelligence/Model/NormalEquations.php',
            __DIR__ . '/../src/Services/PuzzleIntelligence/Prior/PriorContext.php',
            __DIR__ . '/../src/Services/PuzzleIntelligence/Prior/PriorEstimate.php',
            // one sync of ChangedRowsSync / of the rating entries, opened per batch step - not services
            __DIR__ . '/../src/Services/PuzzleIntelligence/ChangedRowsSyncRun.php',
            __DIR__ . '/../src/Services/PuzzleIntelligence/RatingEntriesSync.php',
            // the in-memory state of one Insights computation (a player's rating portfolios and what-ifs) - not services
            __DIR__ . '/../src/Services/Insights/Model/',
            // the Insights page's filter state, one prepared result row and the learning factors of one build - values, not services
            __DIR__ . '/../src/Services/Insights/InsightsCriteria.php',
            __DIR__ . '/../src/Services/Insights/InsightsRow.php',
            __DIR__ . '/../src/Services/Insights/LearningFactors.php',
        ]);

    // framework.session.handler_id. After the Services load, which would otherwise
    // redefine it. The row TTL is explicit on purpose: the fallback of Symfony's own
    // handlers, ini_get('session.gc_maxlifetime'), is only right while
    // NativeSessionStorage::setOptions() gets to apply it, which it skips once headers
    // are sent or a session is active - in FrankenPHP workers that could silently
    // shrink the rows to php.ini's 24 minutes.
    $services->set(PostgresSessionHandler::class)
        ->arg('$lifetimeSeconds', param('loginLifetimeSeconds'));

    $services->set(\SpeedPuzzling\Web\Services\TimesBoardGuestCache::class)
        ->arg('$enabled', '%env(bool:TIMES_BOARD_GUEST_CACHE)%');

    // The bus's transaction (config/packages/messenger.php): Symfony's doctrine_transaction for every message but a
    // HandledOutsideTransaction one
    $services->set(\SpeedPuzzling\Web\Services\MessengerMiddleware\TransactionMiddleware::class)
        ->arg('$transaction', inline_service(\Symfony\Bridge\Doctrine\Messenger\DoctrineTransactionMiddleware::class)->args([service('doctrine')]));

    $services->load('SpeedPuzzling\\Web\\Query\\', __DIR__ . '/../src/Query/**/{*.php}');
    $services->load('SpeedPuzzling\\Web\\BadgeConditions\\', __DIR__ . '/../src/BadgeConditions/**/{*.php}');
    $services->load('SpeedPuzzling\\Web\\Security\\', __DIR__ . '/../src/Security/**/{*.php}')
        ->exclude([
            __DIR__ . '/../src/Security/OAuth2User.php',
            __DIR__ . '/../src/Security/PatUser.php',
            __DIR__ . '/../src/Security/ApiUser.php',
            __DIR__ . '/../src/Security/SignInLinkPasswordPrompt.php',
            __DIR__ . '/../src/Security/SocialRegistrationRequired.php',
            __DIR__ . '/../src/Security/SocialLoginFailed.php',
            __DIR__ . '/../src/Security/SignInCodeRejected.php',
            __DIR__ . '/../src/Security/LoginFormIncomplete.php',
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

    // Amazon SES (docs/features/mass-mailing/README.md): stream marking, suppression and which transport sends
    // (SES first, the retry, the pause, the transactional fallback) sit in front of the mailer's transports; SNS
    // certificates and subscription confirmations are fetched with a client of their own (tests swap it,
    // config/services_test.php)
    $services->set(MailStreamRouting::class)
        ->decorate('mailer.transports')
        ->args([
            '$inner' => service('.inner'),
        ]);

    // ses+https (MAILER_DSN): Symfony's factory hands AsyncAws the framework's HTTP client, and AsyncAws adds its
    // retries (throttling, 5xx, a dropped connection) only to a client it creates itself - so the factory gets a
    // client of its own: retried twice (1 s, 2 s) and bounded, instead of PHP's 60 s socket timeout per wait
    $services->set('ses.http_client', RetryableHttpClient::class)
        ->args([
            inline_service(HttpClientInterface::class)
                ->factory([HttpClient::class, 'create'])
                ->args([['timeout' => 10, 'max_duration' => 20]]),
            inline_service(AwsRetryStrategy::class),
            2,
            service('logger'),
        ]);

    $services->set('mailer.transport_factory.amazon', SesTransportFactory::class)
        ->args([service('event_dispatcher'), service('ses.http_client'), service('logger')])
        ->tag('mailer.transport_factory');

    $services->set('ses_events.http_client', HttpClientInterface::class)
        ->factory([HttpClient::class, 'create'])
        ->args([['timeout' => 5, 'max_redirects' => 0]]);

    // Cloudflare's siteverify for the Turnstile check (tests swap it, config/services_test.php)
    $services->set('turnstile.http_client', HttpClientInterface::class)
        ->factory([HttpClient::class, 'create'])
        ->args([['max_redirects' => 0]]);

    $services->set(SnsMessageVerifier::class)
        ->args([
            '$httpClient' => service('ses_events.http_client'),
            '$cache' => service('sns_certificate_cache'),
            '$allowedTopicArns' => env('SES_EVENTS_TOPIC_ARNS'),
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

    // Result share images (ResultImageCache) live on local disk, never in the bucket. Production mounts a volume
    // shared by web, api and the cron containers here (lily.srv compose.yaml); without it each container keeps
    // its own cards and the prune cron cannot reach them.
    $services->set('app.storage.result_images_adapter', LocalFilesystemAdapter::class)
        ->args([
            env('RESULT_IMAGES_DIR'),
            null,
            \LOCK_EX,
            LocalFilesystemAdapter::DISALLOW_LINKS,
            null,
            true, // lazyRootCreation
        ]);

    $services->set('app.storage.result_images', Filesystem::class)
        ->args([service('app.storage.result_images_adapter')]);

    $services->set(ResultImageCache::class)
        ->arg('$filesystem', service('app.storage.result_images'));

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

    // Dedicated Guzzle client for the league social-login providers: a named
    // service so tests can swap in a MockHandler-backed client and no real
    // provider HTTP ever happens in the test suite
    $services->set('social_login.http_client', \GuzzleHttp\Client::class);

    $services->set(\SpeedPuzzling\Web\Services\SocialLogin\SocialLoginProviders::class)
        ->arg('$httpClient', service('social_login.http_client'));

    // Fetches Apple's and Microsoft's JWKS through the same (test-mockable) client
    $services->set(\SpeedPuzzling\Web\Services\SocialLogin\CachedJwks::class)
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

    // API usage counters (docs/features/api/usage-statistics.md) live in their own
    // Redis (`redis-state`: noeviction + AOF). Lazy: the subscriber is built on every
    // request, the connection only on an API request. Short timeouts - the counting
    // runs after the response, but it still holds a FrankenPHP thread while it waits.
    $services->set('api_usage.redis', \Redis::class)
        ->factory([RedisAdapter::class, 'createConnection'])
        ->args([
            env('REDIS_STATE_DSN'),
            ['lazy' => true, 'timeout' => 0.5, 'read_timeout' => 0.5],
        ]);

    $services->set(RedisApiUsageCounter::class)
        ->arg('$redis', service('api_usage.redis'));

    $services->alias(ApiUsageCounter::class, RedisApiUsageCounter::class);

    // Online competitions (docs/features/online-competitions/): presence in redis-state (the API usage counters'
    // connection), evidence in object storage - config/services_test.php keeps the in-process doubles
    $services->set(RedisOnlinePresence::class)
        ->arg('$redis', service('api_usage.redis'));
    $services->alias(OnlinePresence::class, RedisOnlinePresence::class);

    // The participant API's rate limits (OnlineParticipantApi): every link is a possibly hostile client, so the count
    // must hold when many requests come at the same moment - one atomic Lua call per request on the cache Redis
    // (RedisSlidingWindowLimiterFactory). Framework limiters count in PHP: without a lock parallel requests all pass,
    // and a lock on Redis makes every waiting request sleep in 100 ms steps on a PHP worker (measured:
    // docs/features/online-competitions/README.md §6). Short timeouts - the heartbeat never waits long; a Redis that
    // does not answer lets the request through. config/services_test.php points the API at framework limiters.
    $services->set('online_rate_limiter.redis', \Redis::class)
        ->factory([RedisAdapter::class, 'createConnection'])
        ->args([
            env('REDIS_CACHE_DSN'),
            ['lazy' => true, 'timeout' => 0.5, 'read_timeout' => 0.5],
        ]);

    $onlineRateLimits = [
        // Per client IP, before anything else: links guessed at, or one network flooding. Generous - a club or a
        // family on one connection, each phone a few dozen requests a minute
        'online_participant_ip' => 1_200,
        // Per link: a phone sends a heartbeat every 15 s, event batches, photo uploads every 10 s before the start and
        // the state fetches around it - a few dozen requests a minute even with two devices on one link
        'online_participant' => 300,
        // Per link and endpoint: event batches (up to 50 events each) and presigned uploads - far above a phone
        // catching up after an outage, far below a flood of rows
        'online_participant_events' => 60,
        'online_participant_media' => 60,
    ];

    foreach ($onlineRateLimits as $name => $limit) {
        $services->set('online_rate_limiter.' . $name, RedisSlidingWindowLimiterFactory::class)
            ->args([
                '$redis' => service('online_rate_limiter.redis'),
                '$id' => $name,
                '$limit' => $limit,
                '$intervalSeconds' => 60,
                '$keyPrefix' => 'online_rate_limit:',
            ]);
    }

    $services->set(OnlineParticipantApi::class)
        ->arg('$onlineParticipantIpLimiter', service('online_rate_limiter.online_participant_ip'))
        ->arg('$onlineParticipantLimiter', service('online_rate_limiter.online_participant'))
        ->arg('$onlineParticipantEventsLimiter', service('online_rate_limiter.online_participant_events'))
        ->arg('$onlineParticipantMediaLimiter', service('online_rate_limiter.online_participant_media'));

    // The circuit breaker's failure counters (OnlineStoreBreaker) - asked only while a second store is configured
    $services->set(OnlineStoreBreaker::class)
        ->arg('$redis', service('api_usage.redis'));

    // The primary store is the site's bucket: the server reaches it through the site's S3Client, a browser through
    // ONLINE_MEDIA_PUBLIC_ENDPOINT (empty = S3_ENDPOINT, production's public Hetzner endpoint). The second store is
    // optional (empty endpoint = none).
    $services->set(OnlineMediaStores::class)
        ->args([
            '$primaryClient' => service(S3Client::class),
            '$primaryRegion' => env('S3_REGION'),
            '$primaryBucket' => env('S3_BUCKET'),
            '$primaryAccessKey' => env('S3_ACCESS_KEY'),
            '$primarySecretKey' => env('S3_SECRET_KEY'),
            '$primaryPublicEndpoint' => env('ONLINE_MEDIA_PUBLIC_ENDPOINT')->default('online_media_s3_endpoint'),
            '$secondaryEndpoint' => env('ONLINE_MEDIA_SECONDARY_ENDPOINT'),
            '$secondaryRegion' => env('ONLINE_MEDIA_SECONDARY_REGION'),
            '$secondaryBucket' => env('ONLINE_MEDIA_SECONDARY_BUCKET'),
            '$secondaryAccessKey' => env('ONLINE_MEDIA_SECONDARY_ACCESS_KEY'),
            '$secondarySecretKey' => env('ONLINE_MEDIA_SECONDARY_SECRET_KEY'),
            '$secondaryPathStyle' => env('ONLINE_MEDIA_SECONDARY_PATH_STYLE')->bool(),
            '$httpClient' => service('app.storage.s3_http_client'),
            '$logger' => service('monolog.logger.object_storage'),
            // The CV worker of the completeness check reads by URLs made for this one (OnlineCvInputs)
            '$primaryServerEndpoint' => env('S3_ENDPOINT'),
        ]);
    $services->alias(OnlineMediaStorage::class, S3OnlineMediaStorage::class);

    // Sentry Traces Sampler with profiling trigger support
    $services->set(SentryTracesSampler::class)
        ->arg('$profilingSecret', env('PROFILING_TRIGGER_SECRET'))
        ->arg('$defaultTracesSampleRate', env('SENTRY_TRACES_SAMPLE_RATE')->float());

    $services->set('sentry.traces_sampler', \Closure::class)
        ->factory([service(SentryTracesSampler::class), '__invoke']);

    // No personal link's token of an online competition reaches Sentry (config/packages/sentry.php)
    $services->set('sentry.before_send', \Closure::class)
        ->factory([service(OnlineTokenSentryScrubber::class), 'beforeSend']);
    $services->set('sentry.before_breadcrumb', \Closure::class)
        ->factory([service(OnlineTokenSentryScrubber::class), 'beforeBreadcrumb']);
};
