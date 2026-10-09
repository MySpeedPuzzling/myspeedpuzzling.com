<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CompetitionSlugTaken;
use SpeedPuzzling\Web\Exceptions\OrganizationSlugTaken;
use SpeedPuzzling\Web\Exceptions\SeriesAlreadyInOrganization;
use SpeedPuzzling\Web\Message\CreateOrganizationFromSeries;
use SpeedPuzzling\Web\Message\FollowCompetition;
use SpeedPuzzling\Web\Query\GetEventUrlRedirect;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\EventUrlPath;
use SpeedPuzzling\Web\Value\OrganizationKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/organizations/README.md "Restructuring tools": the organization is made from the series (its fields
 * and team copied), the series' followers follow the organization, the series is attached - renamed, and with a new
 * slug its old addresses redirect.
 */
final class CreateOrganizationFromSeriesHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheOrganizationIsMadeFromTheSeries(): void
    {
        $this->database->executeStatement(
            'INSERT INTO competition_series_maintainer (competition_series_id, player_id) VALUES (:seriesId, :playerId)',
            ['seriesId' => CompetitionSeriesFixture::SERIES_EJJ, 'playerId' => PlayerFixture::PLAYER_WITH_FAVORITES],
        );
        $this->messageBus->dispatch(new FollowCompetition(PlayerFixture::PLAYER_REGULAR, 'series:' . CompetitionSeriesFixture::SERIES_EJJ));
        $this->messageBus->dispatch(new FollowCompetition(PlayerFixture::PLAYER_WITH_STRIPE, 'series:' . CompetitionSeriesFixture::SERIES_EJJ));
        $this->clearEntityManager();

        $organizationId = Uuid::uuid7();
        $this->messageBus->dispatch(new CreateOrganizationFromSeries(
            seriesId: CompetitionSeriesFixture::SERIES_EJJ,
            organizationId: $organizationId,
            actingPlayerId: PlayerFixture::PLAYER_ADMIN,
            name: 'Euro Jigsaw Jam Association',
            shortName: 'EJJA',
            // The organization takes over the series' address
            slug: 'euro-jigsaw-jam-series',
            kind: OrganizationKind::Association,
            countryCode: null,
            region: 'Europe',
            approve: true,
            newSeriesName: 'Euro Jigsaw Jam Online',
            newSeriesSlug: 'euro-jigsaw-jam-online',
        ));
        $this->clearEntityManager();

        $organization = self::getContainer()->get(OrganizationRepository::class)->get($organizationId->toString());
        self::assertSame('Euro Jigsaw Jam Association', $organization->name);
        self::assertSame('EJJA', $organization->shortName);
        self::assertSame('euro-jigsaw-jam-series', $organization->slug);
        self::assertSame(OrganizationKind::Association, $organization->kind);
        self::assertSame('Monthly online jigsaw puzzle competition', $organization->about);
        self::assertSame('https://eurojj.com', $organization->website);
        self::assertSame('Europe', $organization->region);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $organization->addedByPlayer?->id->toString());
        self::assertTrue($organization->isPubliclyVisible());
        // The series' creator is its creator - the other maintainers are its team
        self::assertSame(
            [PlayerFixture::PLAYER_WITH_FAVORITES],
            array_values(array_map(static fn (Player $player): string => $player->id->toString(), $organization->maintainers->toArray())),
        );

        $series = self::getContainer()->get(CompetitionSeriesRepository::class)->get(CompetitionSeriesFixture::SERIES_EJJ);
        self::assertSame($organizationId->toString(), $series->organization?->id->toString());
        self::assertSame('Euro Jigsaw Jam Online', $series->name);
        self::assertSame('euro-jigsaw-jam-online', $series->slug);
        self::assertTrue($series->isApproved());

        // A public organization: the follows move, one row per player
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM followed_competition WHERE series_id = :id', ['id' => CompetitionSeriesFixture::SERIES_EJJ]));
        self::assertSame(2, $this->database->fetchOne('SELECT COUNT(*) FROM followed_competition WHERE organization_id = :id', ['id' => $organizationId->toString()]));

        $redirects = self::getContainer()->get(GetEventUrlRedirect::class);
        self::assertSame(
            ['route' => 'organization_detail', 'params' => ['slug' => 'euro-jigsaw-jam-series']],
            $redirects->target(EventUrlPath::series('euro-jigsaw-jam-series')),
        );
        self::assertSame(
            ['route' => 'edition_detail', 'params' => ['seriesSlug' => 'euro-jigsaw-jam-online', 'editionSlug' => 'ejj-68-february-2026']],
            $redirects->target(EventUrlPath::edition('euro-jigsaw-jam-series', 'ejj-68-february-2026')),
        );
        self::assertSame(
            ['route' => 'edition_round_results', 'params' => ['seriesSlug' => 'euro-jigsaw-jam-online', 'editionSlug' => 'ejj-68-february-2026', 'roundSlug' => 'main-round']],
            $redirects->target(EventUrlPath::editionRound('euro-jigsaw-jam-series', 'ejj-68-february-2026', 'main-round')),
        );
    }

    public function testWithoutApprovalItWaitsAndTheAdminIsTold(): void
    {
        $this->messageBus->dispatch(new FollowCompetition(PlayerFixture::PLAYER_REGULAR, 'series:' . CompetitionSeriesFixture::SERIES_OFFLINE));
        $this->messageBus->dispatch(new FollowCompetition(PlayerFixture::PLAYER_WITH_STRIPE, 'series:' . CompetitionSeriesFixture::SERIES_OFFLINE));
        $this->clearEntityManager();

        $organizationId = Uuid::uuid7();
        $this->messageBus->dispatch(new CreateOrganizationFromSeries(
            seriesId: CompetitionSeriesFixture::SERIES_OFFLINE,
            organizationId: $organizationId,
            actingPlayerId: PlayerFixture::PLAYER_ADMIN,
            name: 'Prague Puzzle Club',
            shortName: null,
            slug: null,
            kind: null,
            countryCode: null,
            region: null,
            approve: false,
        ));
        $this->clearEntityManager();

        $organization = self::getContainer()->get(OrganizationRepository::class)->get($organizationId->toString());
        self::assertFalse($organization->isApproved());
        self::assertSame('prague-puzzle-club', $organization->slug);
        // Country and region as given - none (the callers prefill them from the series)
        self::assertNull($organization->countryCode);
        self::assertNull($organization->region);
        self::assertQueuedEmailCount(1);

        // Nobody can follow an organization waiting for approval: the series follows stay, an organization follow is added
        self::assertSame(2, $this->database->fetchOne('SELECT COUNT(*) FROM followed_competition WHERE series_id = :id', ['id' => CompetitionSeriesFixture::SERIES_OFFLINE]));
        self::assertSame(2, $this->database->fetchOne('SELECT COUNT(*) FROM followed_competition WHERE organization_id = :id', ['id' => $organizationId->toString()]));

        // The series keeps its name, address and approval - and its old address needs no redirect
        $series = self::getContainer()->get(CompetitionSeriesRepository::class)->get(CompetitionSeriesFixture::SERIES_OFFLINE);
        self::assertSame('puzzle-meetup-prague', $series->slug);
        self::assertTrue($series->isApproved());
        self::assertNull(self::getContainer()->get(GetEventUrlRedirect::class)->target(EventUrlPath::series('puzzle-meetup-prague')));
    }

    public function testAPendingSeriesUnderTheApprovedOrganizationIsApproved(): void
    {
        $this->messageBus->dispatch(new CreateOrganizationFromSeries(
            seriesId: CompetitionSeriesFixture::SERIES_UNAPPROVED,
            organizationId: Uuid::uuid7(),
            actingPlayerId: PlayerFixture::PLAYER_ADMIN,
            name: 'Pending Puzzle League Association',
            shortName: null,
            slug: null,
            kind: null,
            countryCode: null,
            region: null,
            approve: true,
        ));
        $this->clearEntityManager();

        self::assertTrue(self::getContainer()->get(CompetitionSeriesRepository::class)->get(CompetitionSeriesFixture::SERIES_UNAPPROVED)->isApproved());
    }

    public function testASeriesOfAnOrganizationIsRefused(): void
    {
        $this->expectException(SeriesAlreadyInOrganization::class);

        $this->dispatchFor(OrganizationFixture::SERIES_LANTERN_NIGHTS, null, null);
    }

    public function testTakenSlugsAreRefusedAndNothingIsCreated(): void
    {
        $organizations = $this->database->fetchOne('SELECT COUNT(*) FROM organization');

        try {
            $this->dispatchFor(CompetitionSeriesFixture::SERIES_OFFLINE, OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG, null);
            self::fail('A taken organization slug must be refused');
        } catch (OrganizationSlugTaken) {
        }

        try {
            $this->dispatchFor(CompetitionSeriesFixture::SERIES_OFFLINE, null, OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG);
            self::fail('A taken series slug must be refused');
        } catch (CompetitionSlugTaken) {
        }

        self::assertSame($organizations, $this->database->fetchOne('SELECT COUNT(*) FROM organization'));
    }

    private function dispatchFor(string $seriesId, null|string $slug, null|string $newSeriesSlug): void
    {
        $this->messageBus->dispatch(new CreateOrganizationFromSeries(
            seriesId: $seriesId,
            organizationId: Uuid::uuid7(),
            actingPlayerId: PlayerFixture::PLAYER_ADMIN,
            name: 'Some Puzzle Organization',
            shortName: null,
            slug: $slug,
            kind: null,
            countryCode: null,
            region: null,
            approve: true,
            newSeriesSlug: $newSeriesSlug,
        ));
    }

    private function clearEntityManager(): void
    {
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();
    }
}
