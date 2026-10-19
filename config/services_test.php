<?php

declare(strict_types=1);

use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;
use Symfony\Component\PropertyInfo\PropertyInfoCacheExtractor;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->defaults()
        ->autoconfigure()
        ->autowire()
        ->public();

    // Data fixtures
    $services->load('SpeedPuzzling\\Web\\Tests\\DataFixtures\\', __DIR__ . '/../tests/DataFixtures/{*.php}');

    // Puzzle Intelligence services (public for testing)
    $services->load('SpeedPuzzling\\Web\\Services\\PuzzleIntelligence\\', __DIR__ . '/../src/Services/PuzzleIntelligence/{*.php}')
        ->exclude([
            __DIR__ . '/../src/Services/PuzzleIntelligence/ChangedRowsSyncRun.php',
            __DIR__ . '/../src/Services/PuzzleIntelligence/RatingEntriesSync.php',
        ]);

    // Insights builders and their read models (public for testing; nothing outside the tests uses them before Phase 6)
    $services->load('SpeedPuzzling\\Web\\Services\\Insights\\', __DIR__ . '/../src/Services/Insights/{*.php}')
        ->exclude([
            // values, not services (excluded in services.php too)
            __DIR__ . '/../src/Services/Insights/InsightsCriteria.php',
            __DIR__ . '/../src/Services/Insights/InsightsRow.php',
            __DIR__ . '/../src/Services/Insights/LearningFactors.php',
            // needs the INSIGHTS_PUBLIC binding of services.php - already public there
            __DIR__ . '/../src/Services/Insights/InsightsFeature.php',
        ]);
    $services->set(\SpeedPuzzling\Web\Query\GetInsightsGroupResults::class)->public();
    $services->set(\SpeedPuzzling\Web\Query\GetInsightsRatingInputs::class)->public();
    $services->set(\SpeedPuzzling\Web\Query\GetInsightsShelf::class)->public();
    $services->set(\SpeedPuzzling\Web\Query\GetInsightsPaceHistory::class)->public();
    $services->set(\SpeedPuzzling\Web\Query\GetInsightsFingerprint::class)->public();

    // Competition participant services (public for testing)
    $services->set(\SpeedPuzzling\Web\Services\CompetitionParticipantImporter::class)->public();
    $services->set(\SpeedPuzzling\Web\Services\CompetitionParticipantExporter::class)->public();
    $services->set(\SpeedPuzzling\Web\Query\GetCompetitionParticipantsForManagement::class)->public();

    // Rolls back a nested DeletePlayer - proves its post-commit work is dropped
    $services->set(\SpeedPuzzling\Web\Tests\TestDouble\DeletePlayerThenFailHandler::class);

    // Fails a test whose form POST answers 200 - Turbo Drive would discard it in the browser
    $services->set(\SpeedPuzzling\Web\Tests\TestDouble\SilentFormSubmissionGuard::class)->tag('kernel.event_subscriber');

    // OAuth2 client secrets: bcrypt at its lowest cost. The fixtures store plaintext
    // secrets, which the bundle re-hashes on every token request - at the default cost
    // 13 that made each OAuth2 test spend ~half a second hashing.
    $services->set('league.oauth2_server.password_hasher', NativePasswordHasher::class)
        ->args([null, null, 4]);

    // FrameworkBundle drops the PropertyInfo cache in debug mode. Production has it; in
    // the suite every kernel boot re-parsed the doc blocks behind each Live Component
    // prop (phpDocumentor's ContextFactory) - an eighth of the suite's CPU time.
    $services->set('property_info.cache', PropertyInfoCacheExtractor::class)
        ->decorate('property_info')
        ->args([service('property_info.cache.inner'), service('cache.property_info')]);

    // API usage counters without Redis - CI's functional tests run no Redis service
    // (RedisApiUsageCounterTest covers the Lua script against a real one)
    $services->set(\SpeedPuzzling\Web\Tests\TestDouble\InMemoryApiUsageCounter::class);
    $services->alias(\SpeedPuzzling\Web\Services\ApiUsage\ApiUsageCounter::class, \SpeedPuzzling\Web\Tests\TestDouble\InMemoryApiUsageCounter::class);

    // Online competitions: presence and evidence storage without Redis and object storage - their doubles keep what a
    // test wrote for the next request of the same kernel (docs/features/online-competitions/)
    $services->alias(\SpeedPuzzling\Web\Services\OnlineRounds\OnlinePresence::class, \SpeedPuzzling\Web\Services\OnlineRounds\InMemoryOnlinePresence::class);
    $services->alias(\SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStorage::class, \SpeedPuzzling\Web\Services\OnlineRounds\FakeOnlineMediaStorage::class);

    // Live video (docs/features/online-competitions/contracts/phase-1b.md): LiveKit's server API answers from a static
    // MockHttpClient that records every request - no test ever needs a running LiveKit -, and the studio invitation
    // signal lives in the process instead of redis-state (both doubles survive the browser's kernel reboots)
    $services->set('livekit.test_http_client', \Symfony\Component\HttpClient\MockHttpClient::class)
        ->factory([\SpeedPuzzling\Web\Tests\TestDouble\LiveKitHttpMock::class, 'client']);
    $services->set(\SpeedPuzzling\Web\Services\LiveKit\LiveKitApi::class)
        ->arg('$httpClient', service('livekit.test_http_client'));
    $services->set(\SpeedPuzzling\Web\Tests\TestDouble\InMemoryStudioInviteSignal::class);
    $services->alias(\SpeedPuzzling\Web\Services\LiveKit\StudioInviteSignal::class, \SpeedPuzzling\Web\Tests\TestDouble\InMemoryStudioInviteSignal::class);

    // The completeness check's CV worker (Phase 3, tools/online-cv/) answers from a static MockHttpClient that records
    // every request - no test talks to a real worker (it survives the browser's kernel reboots, like LiveKit's)
    $services->set('online_cv.test_http_client', \Symfony\Component\HttpClient\MockHttpClient::class)
        ->factory([\SpeedPuzzling\Web\Tests\TestDouble\OnlineCvHttpMock::class, 'client']);
    $services->set(\SpeedPuzzling\Web\Services\OnlineRounds\OnlineCvClient::class)
        ->arg('$httpClient', service('online_cv.test_http_client'));

    // The participant API's rate limits without Redis: the framework limiters of config/packages/test/rate_limiter.php
    // (RedisSlidingWindowLimiterTest covers the production limiter against a real Redis)
    foreach (['online_participant_ip', 'online_participant', 'online_participant_events', 'online_participant_media'] as $name) {
        $services->alias('online_rate_limiter.' . $name, 'limiter.' . $name);
    }

    // The XP launch switch without a statement per page (production: Redis) - see FixedXpLaunchReadiness
    $services->set(\SpeedPuzzling\Web\Tests\TestDouble\FixedXpLaunchReadiness::class);
    $services->alias(\SpeedPuzzling\Web\Services\Xp\XpLaunchReadiness::class, \SpeedPuzzling\Web\Tests\TestDouble\FixedXpLaunchReadiness::class);

    // Mercure test double
    $services->set(NullMercureHub::class);
    $services->alias(HubInterface::class, NullMercureHub::class);

    // Social login providers talk to a Guzzle MockHandler - tests must never
    // call Google/Apple/Facebook (the static handler survives kernel reboots)
    $services->set('social_login.http_client', \GuzzleHttp\Client::class)
        ->factory([\SpeedPuzzling\Web\Tests\TestDouble\SocialLoginHttpMock::class, 'client']);

    // SNS certificates + subscription confirmations never leave the process (static, like the social login mock)
    $services->set('ses_events.http_client', \Symfony\Component\HttpClient\MockHttpClient::class)
        ->factory([\SpeedPuzzling\Web\Tests\TestDouble\SnsHttpMock::class, 'client']);

    // Cloudflare's siteverify for the Turnstile check never leaves the process (static, like the SNS mock)
    $services->set('turnstile.http_client', \Symfony\Component\HttpClient\MockHttpClient::class)
        ->factory([\SpeedPuzzling\Web\Tests\TestDouble\TurnstileHttpMock::class, 'client']);

    // S3 failover: the real FailoverS3Adapter stays wired (see
    // config/packages/test/oneup_flysystem.php), only the raw S3 adapter and
    // the local spool are swapped for in-memory doubles. setFailing(true) on
    // the toggleable double simulates an object storage outage.
    $services->set('app.storage.s3_adapter', \SpeedPuzzling\Web\Tests\TestDouble\ToggleableFailingFilesystemAdapter::class);
    $services->set('app.storage.spool_adapter', \League\Flysystem\InMemory\InMemoryFilesystemAdapter::class);
    $services->set('app.storage.result_images_adapter', \League\Flysystem\InMemory\InMemoryFilesystemAdapter::class);
};
