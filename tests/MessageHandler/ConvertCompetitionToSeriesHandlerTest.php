<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ConvertCompetitionToSeries;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ConvertCompetitionToSeriesHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionRepository $competitionRepository;
    private CompetitionSeriesRepository $seriesRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->competitionRepository = self::getContainer()->get(CompetitionRepository::class);
        $this->seriesRepository = self::getContainer()->get(CompetitionSeriesRepository::class);
    }

    public function testConvertCreatesSeriesWithCorrectFields(): void
    {
        $seriesId = Uuid::uuid7();
        $competitionId = CompetitionFixture::COMPETITION_RECURRING_ONLINE;

        $competition = $this->competitionRepository->get($competitionId);
        $originalName = $competition->name;
        $originalDescription = $competition->description;
        $originalLogo = $competition->logo;
        $originalLink = $competition->link;

        $this->messageBus->dispatch(new ConvertCompetitionToSeries(
            competitionId: $competitionId,
            seriesId: $seriesId,
        ));

        $series = $this->seriesRepository->get($seriesId->toString());

        self::assertSame($originalName, $series->name);
        self::assertSame($originalDescription, $series->description);
        self::assertSame($originalLogo, $series->logo);
        self::assertSame($originalLink, $series->link);
        self::assertTrue($series->isOnline);
        self::assertNotNull($series->slug);
        self::assertNotNull($series->approvedAt);
        self::assertNotNull($series->addedByPlayer);
    }

    public function testCompetitionBecomesEditionOfSeries(): void
    {
        $seriesId = Uuid::uuid7();
        $competitionId = CompetitionFixture::COMPETITION_RECURRING_ONLINE;

        $this->messageBus->dispatch(new ConvertCompetitionToSeries(
            competitionId: $competitionId,
            seriesId: $seriesId,
        ));

        $competition = $this->competitionRepository->get($competitionId);

        self::assertNotNull($competition->series);
        self::assertSame($seriesId->toString(), $competition->series->id->toString());
    }

    public function testCompetitionFieldsAreNulledAfterConversion(): void
    {
        $seriesId = Uuid::uuid7();
        $competitionId = CompetitionFixture::COMPETITION_RECURRING_ONLINE;

        $this->messageBus->dispatch(new ConvertCompetitionToSeries(
            competitionId: $competitionId,
            seriesId: $seriesId,
        ));

        $competition = $this->competitionRepository->get($competitionId);

        self::assertNull($competition->shortcut);
        self::assertNull($competition->logo);
        self::assertNull($competition->description);
        self::assertNull($competition->link);
        self::assertNull($competition->tag);
        self::assertNull($competition->approvedAt);
        self::assertNull($competition->approvedByPlayer);
        self::assertNull($competition->rejectedAt);
        self::assertNull($competition->rejectedByPlayer);
        self::assertNull($competition->rejectionReason);
    }

    public function testMaintainersAreMovedToSeries(): void
    {
        $seriesId = Uuid::uuid7();
        $competitionId = CompetitionFixture::COMPETITION_RECURRING_ONLINE;

        $this->messageBus->dispatch(new ConvertCompetitionToSeries(
            competitionId: $competitionId,
            seriesId: $seriesId,
        ));

        $series = $this->seriesRepository->get($seriesId->toString());
        $competition = $this->competitionRepository->get($competitionId);

        self::assertGreaterThan(0, $series->maintainers->count());
        self::assertCount(0, $competition->maintainers);
    }

    public function testCompetitionSlugIsPreserved(): void
    {
        $seriesId = Uuid::uuid7();
        $competitionId = CompetitionFixture::COMPETITION_RECURRING_ONLINE;

        $competition = $this->competitionRepository->get($competitionId);
        $originalSlug = $competition->slug;

        $this->messageBus->dispatch(new ConvertCompetitionToSeries(
            competitionId: $competitionId,
            seriesId: $seriesId,
        ));

        $competition = $this->competitionRepository->get($competitionId);
        self::assertSame($originalSlug, $competition->slug);
    }

    public function testConvertOfflineCompetitionCreatesOfflineSeries(): void
    {
        $seriesId = Uuid::uuid7();
        $competitionId = CompetitionFixture::COMPETITION_WJPC_2024;

        $competition = $this->competitionRepository->get($competitionId);
        $originalLocation = $competition->location;
        $originalLocationCountryCode = $competition->locationCountryCode;

        $this->messageBus->dispatch(new ConvertCompetitionToSeries(
            competitionId: $competitionId,
            seriesId: $seriesId,
        ));

        $series = $this->seriesRepository->get($seriesId->toString());

        self::assertFalse($series->isOnline);
        self::assertSame($originalLocation, $series->location);
        self::assertSame($originalLocationCountryCode, $series->locationCountryCode);
        self::assertNotNull($series->slug);
    }

    /**
     * docs/features/organizations/README.md: what belongs to the whole moves to the new series - its organization, its
     * draft state and "Who can enter"; the event, now an edition, keeps none of them (an edition never has its own
     * organization).
     */
    public function testARejectedEventStaysRejectedAsASeries(): void
    {
        $seriesId = Uuid::uuid7();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            "UPDATE competition SET rejected_at = NOW(), rejection_reason = 'Not a puzzle event' WHERE id = :id",
            ['id' => CompetitionFixture::COMPETITION_RECURRING_ONLINE],
        );

        $this->messageBus->dispatch(new ConvertCompetitionToSeries(
            competitionId: CompetitionFixture::COMPETITION_RECURRING_ONLINE,
            seriesId: $seriesId,
        ));

        $series = $this->seriesRepository->get($seriesId->toString());

        self::assertTrue($series->isRejected());
        self::assertSame('Not a puzzle event', $series->rejectionReason);
        self::assertFalse($series->isPubliclyVisible());
    }

    public function testOrganizationDraftAndWhoCanEnterMoveToTheSeries(): void
    {
        $seriesId = Uuid::uuid7();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'UPDATE competition SET is_draft = true WHERE id = :id',
            ['id' => OrganizationFixture::COMPETITION_RIVERBEND_OPEN],
        );

        $this->messageBus->dispatch(new ConvertCompetitionToSeries(
            competitionId: OrganizationFixture::COMPETITION_RIVERBEND_OPEN,
            seriesId: $seriesId,
        ));

        $series = $this->seriesRepository->get($seriesId->toString());

        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $series->organization?->id->toString());
        self::assertTrue($series->isDraft);
        self::assertSame('Residents of Riverbend Valley', $series->eligibility);

        $competition = $this->competitionRepository->get(OrganizationFixture::COMPETITION_RIVERBEND_OPEN);

        self::assertSame($seriesId->toString(), $competition->series?->id->toString());
        self::assertNull($competition->organization);
        self::assertFalse($competition->isDraft);
        self::assertNull($competition->eligibility);

        self::assertNull($connection->fetchOne(
            'SELECT organization_id FROM competition WHERE id = :id',
            ['id' => OrganizationFixture::COMPETITION_RIVERBEND_OPEN],
        ));
    }

    /**
     * An edition is followed through its series (docs/features/events-page/README.md, "Follow") - the event's followers
     * follow the new series.
     */
    public function testFollowersOfTheEventFollowTheNewSeries(): void
    {
        $seriesId = Uuid::uuid7();

        $this->messageBus->dispatch(new ConvertCompetitionToSeries(
            competitionId: EventsPageFixture::COMPETITION_MEADOW_TBA,
            seriesId: $seriesId,
        ));

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $row = $connection->fetchAssociative(
            'SELECT competition_id, series_id FROM followed_competition WHERE id = :id',
            ['id' => EventsPageFixture::FOLLOW_REGULAR_MEADOW],
        );

        self::assertIsArray($row);
        self::assertNull($row['competition_id']);
        self::assertSame($seriesId->toString(), $row['series_id']);
        self::assertSame(1, $connection->fetchOne(
            'SELECT COUNT(*) FROM followed_competition WHERE player_id = :player AND series_id = :series',
            ['player' => PlayerFixture::PLAYER_REGULAR, 'series' => $seriesId->toString()],
        ));
    }
}
