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
    $services->load('SpeedPuzzling\\Web\\Services\\PuzzleIntelligence\\', __DIR__ . '/../src/Services/PuzzleIntelligence/{*.php}');

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

    // Mercure test double
    $services->set(NullMercureHub::class);
    $services->alias(HubInterface::class, NullMercureHub::class);

    // Social login providers talk to a Guzzle MockHandler - tests must never
    // call Google/Apple/Facebook (the static handler survives kernel reboots)
    $services->set('social_login.http_client', \GuzzleHttp\Client::class)
        ->factory([\SpeedPuzzling\Web\Tests\TestDouble\SocialLoginHttpMock::class, 'client']);

    // S3 failover: the real FailoverS3Adapter stays wired (see
    // config/packages/test/oneup_flysystem.php), only the raw S3 adapter and
    // the local spool are swapped for in-memory doubles. setFailing(true) on
    // the toggleable double simulates an object storage outage.
    $services->set('app.storage.s3_adapter', \SpeedPuzzling\Web\Tests\TestDouble\ToggleableFailingFilesystemAdapter::class);
    $services->set('app.storage.spool_adapter', \League\Flysystem\InMemory\InMemoryFilesystemAdapter::class);
};
