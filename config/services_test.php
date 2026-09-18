<?php

declare(strict_types=1);

use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Mercure\HubInterface;

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

    // Fails a test whose form POST answers 200 - Turbo Drive would discard it in the browser
    $services->set(\SpeedPuzzling\Web\Tests\TestDouble\SilentFormSubmissionGuard::class)->tag('kernel.event_subscriber');

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
