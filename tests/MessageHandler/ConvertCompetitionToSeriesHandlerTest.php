<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionAlreadyInSeries;
use SpeedPuzzling\Web\Exceptions\CompetitionNotConvertible;
use SpeedPuzzling\Web\Message\ConvertCompetitionToSeries;
use SpeedPuzzling\Web\Query\GetEventUrlRedirect;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\EventUrlPath;
use SpeedPuzzling\Web\Value\SeriesConversionBlocker;
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

    /**
     * H12 11 (docs/features/events-page/high-frequency-series.md): the web button keeps the event as the first edition -
     * its times stay linked to it, explicitly, nothing lost.
     */
    public function testKeepingTheEventAsTheFirstEditionKeepsItsTimesExplicit(): void
    {
        $scenario = new SeriesEditionScenario(self::getContainer());
        $time = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, $scenario->puzzle(), '2026-03-02', competitionId: EventsPageFixture::COMPETITION_MEADOW_TBA);
        $seriesId = Uuid::uuid7();

        $scenario->dispatch(new ConvertCompetitionToSeries(EventsPageFixture::COMPETITION_MEADOW_TBA, $seriesId));

        self::assertSame($seriesId->toString(), $this->competitionRepository->get(EventsPageFixture::COMPETITION_MEADOW_TBA)->series?->id->toString());
        self::assertSame(
            ['competition_id' => EventsPageFixture::COMPETITION_MEADOW_TBA, 'competition_series_id' => null, 'series_edition_match' => null, 'competition_round_id' => null],
            $scenario->link($time),
        );
    }

    /**
     * H9, H12 11: the event becomes the series - its times become series-level picks of it (nothing lost), its followers
     * and old addresses go to the series, the competition row goes. The series' first edition matches the times.
     */
    public function testTheEventBecomesTheSeries(): void
    {
        $scenario = new SeriesEditionScenario(self::getContainer());
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $first = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, $scenario->puzzle(), '2026-03-02', competitionId: EventsPageFixture::COMPETITION_MEADOW_TBA);
        $second = $scenario->addTime(PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID, $scenario->puzzle(), '2026-03-09', competitionId: EventsPageFixture::COMPETITION_MEADOW_TBA);
        // An old address leading to the event (a renamed slug, say)
        $connection->executeStatement(
            "INSERT INTO event_url_redirect (id, series_slug, competition_slug, round_slug, competition_id, created_at) VALUES (:id, '', 'old-meadow-cup', '', :competition, NOW())",
            ['id' => Uuid::uuid7()->toString(), 'competition' => EventsPageFixture::COMPETITION_MEADOW_TBA],
        );
        $seriesId = Uuid::uuid7();

        $scenario->dispatch(new ConvertCompetitionToSeries(EventsPageFixture::COMPETITION_MEADOW_TBA, $seriesId, keepAsEdition: false));

        $series = $this->seriesRepository->get($seriesId->toString());
        self::assertSame(EventsPageFixture::COMPETITION_MEADOW_TBA_NAME, $series->name);
        self::assertSame('meadow-puzzle-championship', $series->slug);
        self::assertFalse($this->competitionRepository->has(Uuid::fromString(EventsPageFixture::COMPETITION_MEADOW_TBA)));

        $seriesLevel = ['competition_id' => null, 'competition_series_id' => $seriesId->toString(), 'series_edition_match' => null, 'competition_round_id' => null];
        self::assertSame($seriesLevel, $scenario->link($first));
        self::assertSame($seriesLevel, $scenario->link($second));

        self::assertSame($seriesId->toString(), $connection->fetchOne('SELECT series_id FROM followed_competition WHERE id = :id', ['id' => EventsPageFixture::FOLLOW_REGULAR_MEADOW]));

        $redirects = self::getContainer()->get(GetEventUrlRedirect::class);
        $toTheSeries = ['route' => 'competition_series_detail', 'params' => ['slug' => 'meadow-puzzle-championship']];
        self::assertSame($toTheSeries, $redirects->target(EventUrlPath::event('meadow-puzzle-championship')));
        self::assertSame($toTheSeries, $redirects->target(EventUrlPath::event('old-meadow-cup')));

        // The first edition on a solve day matches that time - by date
        $edition = $scenario->edition($seriesId->toString(), 'Meadow Night No. 1', '2026-03-09');
        self::assertSame($edition, $scenario->link($second)['competition_id']);
        self::assertSame('date', $scenario->link($second)['series_edition_match']);
        self::assertNull($scenario->link($first)['competition_id']);
    }

    /**
     * Anything of the event that would be lost refuses the conversion - nothing changes.
     */
    public function testWhatWouldBeLostRefusesTheEventBecomingTheSeries(): void
    {
        self::assertSame([SeriesConversionBlocker::Rounds], $this->refusalOf(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024));
        self::assertSame(
            [SeriesConversionBlocker::MarketplaceMarks, SeriesConversionBlocker::Participants],
            $this->refusalOf(MarketplaceEventFixture::COMPETITION_SWAP_FAIR),
        );

        $officialResults = $this->refusalOf(OfficialResultsFixture::COMPETITION_RESULTS_CUP);
        self::assertContains(SeriesConversionBlocker::Rounds, $officialResults);
        self::assertContains(SeriesConversionBlocker::OfficialResults, $officialResults);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'INSERT INTO competition_referee (id, competition_id, player_id, added_at) VALUES (:id, :competition, :player, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'competition' => EventsPageFixture::COMPETITION_MEADOW_TBA, 'player' => PlayerFixture::PLAYER_REGULAR],
        );
        $connection->executeStatement(
            "INSERT INTO competition_page_section (id, competition_id, type, position, content, created_at, visible) VALUES (:id, :competition, 'text', 1, '{}', NOW(), true)",
            ['id' => Uuid::uuid7()->toString(), 'competition' => EventsPageFixture::COMPETITION_MEADOW_TBA],
        );
        self::assertSame(
            [SeriesConversionBlocker::Referees, SeriesConversionBlocker::PageSections],
            $this->refusalOf(EventsPageFixture::COMPETITION_MEADOW_TBA),
        );
    }

    public function testDroppingTheParticipantsLetsTheEventBecomeTheSeries(): void
    {
        self::assertSame([SeriesConversionBlocker::Participants], $this->refusalOf(EventsPageFixture::COMPETITION_RIVERSIDE_OPEN));

        $seriesId = Uuid::uuid7();
        $this->messageBus->dispatch(new ConvertCompetitionToSeries(EventsPageFixture::COMPETITION_RIVERSIDE_OPEN, $seriesId, keepAsEdition: false, dropParticipants: true));

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM competition_participant WHERE competition_id = :id', ['id' => EventsPageFixture::COMPETITION_RIVERSIDE_OPEN]));
        self::assertFalse($this->competitionRepository->has(Uuid::fromString(EventsPageFixture::COMPETITION_RIVERSIDE_OPEN)));
        self::assertSame($seriesId->toString(), $this->seriesRepository->get($seriesId->toString())->id->toString());
    }

    public function testAnEditionIsAlreadyInASeries(): void
    {
        $scenario = new SeriesEditionScenario(self::getContainer());
        $edition = $scenario->edition($scenario->series(), 'Jam No. 1', '2026-03-02');

        $this->expectException(CompetitionAlreadyInSeries::class);

        $this->messageBus->dispatch(new ConvertCompetitionToSeries($edition, Uuid::uuid7(), keepAsEdition: false));
    }

    /**
     * @return list<SeriesConversionBlocker>
     */
    private function refusalOf(string $competitionId): array
    {
        $seriesId = Uuid::uuid7();

        try {
            $this->messageBus->dispatch(new ConvertCompetitionToSeries($competitionId, $seriesId, keepAsEdition: false));
        } catch (CompetitionNotConvertible $refusal) {
            // Nothing changed
            self::assertTrue($this->competitionRepository->has(Uuid::fromString($competitionId)));
            self::assertFalse(self::getContainer()->get(Connection::class)->fetchOne('SELECT 1 FROM competition_series WHERE id = :id', ['id' => $seriesId->toString()]));

            return $refusal->blockers;
        }

        self::fail('The conversion was expected to be refused');
    }
}
