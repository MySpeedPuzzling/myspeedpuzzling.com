<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionSlugTaken;
use SpeedPuzzling\Web\Exceptions\EditionAlreadyInSeries;
use SpeedPuzzling\Web\Exceptions\EditionNotMovableIntoDraft;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionSlug;
use SpeedPuzzling\Web\Exceptions\NotAnEdition;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\AddEdition;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Message\MoveEditionToSeries;
use SpeedPuzzling\Web\Query\GetEventUrlRedirect;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\EventUrlPath;
use SpeedPuzzling\Web\Value\UnpublishBlocker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/organizations/README.md "Restructuring tools": an edition moves to another series with its rounds; its
 * slug stays unless taken there; its place follows the new series where it was the old one's (P21); its old addresses
 * redirect; its visibility is the new series'.
 */
final class MoveEditionToSeriesHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionRepository $competitionRepository;
    private GetEventUrlRedirect $getEventUrlRedirect;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->competitionRepository = self::getContainer()->get(CompetitionRepository::class);
        $this->getEventUrlRedirect = self::getContainer()->get(GetEventUrlRedirect::class);
    }

    public function testTheEditionMovesWithItsRoundsAndItsOldAddressesRedirect(): void
    {
        $roundId = Uuid::uuid7();
        $this->messageBus->dispatch(new AddCompetitionRound(
            roundId: $roundId,
            competitionId: OrganizationFixture::EDITION_LANTERN_1,
            name: 'Night Round',
            minutesLimit: 60,
            startsAt: new DateTimeImmutable('+22 days 23:00'),
            timezone: 'America/New_York',
            badgeBackgroundColor: null,
            badgeTextColor: null,
        ));

        $this->move(OrganizationFixture::EDITION_LANTERN_1, OrganizationFixture::SERIES_RIVERBEND_VIRTUAL);

        $edition = $this->competitionRepository->get(OrganizationFixture::EDITION_LANTERN_1);
        self::assertSame(OrganizationFixture::SERIES_RIVERBEND_VIRTUAL, $edition->series?->id->toString());
        self::assertSame('lantern-night-one', $edition->slug);
        // Its place was its old series' - it takes the new series' (online, no place)
        self::assertTrue($edition->isOnline);
        self::assertNull($edition->location);

        self::assertSame(
            ['route' => 'edition_detail', 'params' => ['seriesSlug' => OrganizationFixture::SERIES_RIVERBEND_VIRTUAL_SLUG, 'editionSlug' => 'lantern-night-one']],
            $this->getEventUrlRedirect->target(EventUrlPath::edition(OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG, 'lantern-night-one')),
        );
        self::assertSame(
            ['route' => 'edition_round_results', 'params' => ['seriesSlug' => OrganizationFixture::SERIES_RIVERBEND_VIRTUAL_SLUG, 'editionSlug' => 'lantern-night-one', 'roundSlug' => 'night-round']],
            $this->getEventUrlRedirect->target(EventUrlPath::editionRound(OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG, 'lantern-night-one', 'night-round')),
        );

        // The round is still the edition's
        $roundCompetition = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT competition_id FROM competition_round WHERE id = :id',
            ['id' => $roundId->toString()],
        );
        self::assertSame(OrganizationFixture::EDITION_LANTERN_1, $roundCompetition);
    }

    public function testAPlaceChangedForTheEditionStays(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement("UPDATE competition SET location = 'Riverbend Library' WHERE id = :id", ['id' => OrganizationFixture::EDITION_LANTERN_2]);

        $this->move(OrganizationFixture::EDITION_LANTERN_2, OrganizationFixture::SERIES_HARBOR_CLUB_MEETS);

        $edition = $this->competitionRepository->get(OrganizationFixture::EDITION_LANTERN_2);
        self::assertSame('Riverbend Library', $edition->location);
        // The country was the old series' - it follows the new one
        self::assertSame('ie', $edition->locationCountryCode);
    }

    public function testASlugTakenInTheTargetNeedsANewOne(): void
    {
        $this->messageBus->dispatch(new AddEdition(
            competitionId: Uuid::uuid7(),
            seriesId: OrganizationFixture::SERIES_RIVERBEND_VIRTUAL,
            name: 'Lantern Night One',
            dateFrom: null,
            dateTo: null,
            registrationLink: null,
            resultsLink: null,
            slug: 'lantern-night-one',
        ));

        try {
            $this->move(OrganizationFixture::EDITION_LANTERN_1, OrganizationFixture::SERIES_RIVERBEND_VIRTUAL);
            self::fail('A taken slug must be refused');
        } catch (CompetitionSlugTaken) {
            self::assertSame(OrganizationFixture::SERIES_LANTERN_NIGHTS, $this->competitionRepository->get(OrganizationFixture::EDITION_LANTERN_1)->series?->id->toString());
        }

        $this->move(OrganizationFixture::EDITION_LANTERN_1, OrganizationFixture::SERIES_RIVERBEND_VIRTUAL, 'lantern-night-one-online');

        $edition = $this->competitionRepository->get(OrganizationFixture::EDITION_LANTERN_1);
        self::assertSame('lantern-night-one-online', $edition->slug);
        self::assertSame(
            ['route' => 'edition_detail', 'params' => ['seriesSlug' => OrganizationFixture::SERIES_RIVERBEND_VIRTUAL_SLUG, 'editionSlug' => 'lantern-night-one-online']],
            $this->getEventUrlRedirect->target(EventUrlPath::edition(OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG, 'lantern-night-one')),
        );
    }

    public function testAnInvalidNewSlugIsRefused(): void
    {
        $this->expectException(InvalidCompetitionSlug::class);

        $this->move(OrganizationFixture::EDITION_LANTERN_1, OrganizationFixture::SERIES_RIVERBEND_VIRTUAL, 'Not A Slug');
    }

    public function testVisibilityFollowsTheTargetSeries(): void
    {
        $isPubliclyVisible = self::getContainer()->get(IsCompetitionPubliclyVisible::class);
        self::assertTrue($isPubliclyVisible->check(OrganizationFixture::EDITION_LANTERN_1));

        $this->move(OrganizationFixture::EDITION_LANTERN_1, OrganizationFixture::SERIES_QUIET_PINES_DRAFT);

        self::assertFalse($isPubliclyVisible->check(OrganizationFixture::EDITION_LANTERN_1));
    }

    public function testADraftSeriesTakesNoEditionPeopleJoined(): void
    {
        $this->messageBus->dispatch(new JoinCompetition(OrganizationFixture::EDITION_LANTERN_2, PlayerFixture::PLAYER_REGULAR));

        try {
            $this->move(OrganizationFixture::EDITION_LANTERN_2, OrganizationFixture::SERIES_QUIET_PINES_DRAFT);
            self::fail('A draft series must not take an edition somebody joined');
        } catch (EditionNotMovableIntoDraft $exception) {
            self::assertSame([UnpublishBlocker::Participants], $exception->blockers);
        }

        self::assertSame(OrganizationFixture::SERIES_LANTERN_NIGHTS, $this->competitionRepository->get(OrganizationFixture::EDITION_LANTERN_2)->series?->id->toString());
    }

    public function testAOneTimeEventIsNoEdition(): void
    {
        $this->expectException(NotAnEdition::class);

        $this->move(OrganizationFixture::COMPETITION_RIVERBEND_OPEN, OrganizationFixture::SERIES_LANTERN_NIGHTS);
    }

    public function testTheSeriesItIsInAlreadyIsRefused(): void
    {
        $this->expectException(EditionAlreadyInSeries::class);

        $this->move(OrganizationFixture::EDITION_LANTERN_1, OrganizationFixture::SERIES_LANTERN_NIGHTS);
    }

    private function move(string $competitionId, string $seriesId, null|string $newSlug = null): void
    {
        $this->messageBus->dispatch(new MoveEditionToSeries(
            competitionId: $competitionId,
            targetSeriesId: $seriesId,
            actingPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            newSlug: $newSlug,
        ));

        self::getContainer()->get('doctrine.orm.entity_manager')->clear();
    }
}
