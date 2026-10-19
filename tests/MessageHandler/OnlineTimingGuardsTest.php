<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Exceptions\OnlineTimingInUse;
use SpeedPuzzling\Web\Message\EditCompetition;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Message\EditCompetitionSeries;
use SpeedPuzzling\Web\Message\EnableOnlineRound;
use SpeedPuzzling\Web\Message\MoveEditionToSeries;
use SpeedPuzzling\Web\Message\MoveRoundToCompetition;
use SpeedPuzzling\Web\Query\EventIsOnline;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\OnlineTimingAvailability;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Online timing works only for a solo round of an online event (OnlineTimingAvailability, docs/features/online-
 * competitions/) - and stays that way: the changes that would leave it on a round that cannot use it are refused
 * (OnlineTimingInUse) rather than turning it off underneath participants who hold their links. "Online" is one rule,
 * EventIsOnline: a one-time event by its own flag, an edition by its series'.
 */
final class OnlineTimingGuardsTest extends KernelTestCase
{
    private const string COMPETITION = OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP;
    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string SECOND_ROUND = '018d0050-0000-0000-0000-0000000001e1';

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testARoundUsingOnlineTimingStaysSolo(): void
    {
        try {
            $this->editRound(self::ROUND, RoundCategory::Duo);
            self::fail('A round using online timing became a pair round');
        } catch (OnlineTimingInUse $refused) {
            self::assertSame(OnlineTimingInUse::ROUND_CATEGORY, $refused->reason);
            self::assertSame(['Online Round'], $refused->rounds);
            self::assertSame('online_rounds.settings.in_use.round_category', $refused->translationKey());
        }

        self::assertSame('solo', $this->database->fetchOne('SELECT category FROM competition_round WHERE id = :id', ['id' => self::ROUND]));

        // Without online timing a round changes its category as ever
        $this->addRound(self::SECOND_ROUND);
        $this->editRound(self::SECOND_ROUND, RoundCategory::Team);
        self::assertSame('team', $this->database->fetchOne('SELECT category FROM competition_round WHERE id = :id', ['id' => self::SECOND_ROUND]));
    }

    public function testAnEventUsingOnlineTimingStaysOnline(): void
    {
        try {
            $this->editCompetition(isOnline: false);
            self::fail('An event using online timing went in person');
        } catch (OnlineTimingInUse $refused) {
            self::assertSame(OnlineTimingInUse::EVENT_IN_PERSON, $refused->reason);
            self::assertSame(['Online Round'], $refused->rounds);
        }

        self::assertTrue($this->database->fetchOne('SELECT is_online FROM competition WHERE id = :id', ['id' => self::COMPETITION]));

        // Saved online it is no change of that kind
        $this->editCompetition(isOnline: true);

        // Without online timing it may go in person
        $this->database->executeStatement('DELETE FROM online_round WHERE id = :id', ['id' => OnlineRoundsFixture::ONLINE_ROUND_SOLO]);
        $this->editCompetition(isOnline: false);
        self::assertFalse($this->database->fetchOne('SELECT is_online FROM competition WHERE id = :id', ['id' => self::COMPETITION]));
    }

    public function testASeriesWithAnEditionUsingOnlineTimingStaysOnline(): void
    {
        $this->useOnlineTiming(CompetitionSeriesFixture::ROUND_EJJ_69);

        try {
            $this->editSeries(isOnline: false);
            self::fail('A series whose edition uses online timing went in person');
        } catch (OnlineTimingInUse $refused) {
            self::assertSame(OnlineTimingInUse::SERIES_IN_PERSON, $refused->reason);
        }

        self::assertTrue($this->database->fetchOne('SELECT is_online FROM competition_series WHERE id = :id', ['id' => CompetitionSeriesFixture::SERIES_EJJ]));
    }

    public function testARoundOrAnEditionUsingOnlineTimingMovesOnlyToAnOnlineEvent(): void
    {
        // A round with online timing and nobody entered (the move refuses entries anyway)
        $this->addRound(self::SECOND_ROUND);
        $this->messageBus->dispatch(new EnableOnlineRound(self::COMPETITION, self::SECOND_ROUND, PlayerFixture::PLAYER_ADMIN));

        try {
            $this->messageBus->dispatch(new MoveRoundToCompetition(
                roundId: self::SECOND_ROUND,
                competitionId: self::COMPETITION,
                targetCompetitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
                actingPlayerId: PlayerFixture::PLAYER_ADMIN,
            ));
            self::fail('A round using online timing moved to an in-person event');
        } catch (OnlineTimingInUse $refused) {
            self::assertSame(OnlineTimingInUse::ROUND_MOVE, $refused->reason);
        }

        self::assertSame(self::COMPETITION, $this->database->fetchOne('SELECT competition_id FROM competition_round WHERE id = :id', ['id' => self::SECOND_ROUND]));

        $this->useOnlineTiming(CompetitionSeriesFixture::ROUND_EJJ_69);

        try {
            $this->messageBus->dispatch(new MoveEditionToSeries(
                competitionId: CompetitionSeriesFixture::EDITION_EJJ_69,
                targetSeriesId: CompetitionSeriesFixture::SERIES_OFFLINE,
                actingPlayerId: PlayerFixture::PLAYER_ADMIN,
            ));
            self::fail('An edition using online timing moved into an in-person series');
        } catch (OnlineTimingInUse $refused) {
            self::assertSame(OnlineTimingInUse::EDITION_MOVE, $refused->reason);
        }

        self::assertSame(CompetitionSeriesFixture::SERIES_EJJ, $this->database->fetchOne('SELECT series_id FROM competition WHERE id = :id', ['id' => CompetitionSeriesFixture::EDITION_EJJ_69]));
    }

    /**
     * Editions copy their series' flag when made or moved, but a series edited later does not rewrite them - an edition
     * goes by its series, like the events pages
     */
    public function testAnEditionIsOnlineByItsSeries(): void
    {
        $this->database->executeStatement('UPDATE competition SET is_online = false WHERE id = :id', ['id' => CompetitionSeriesFixture::EDITION_EJJ_69]);
        $this->clear();

        $round = self::getContainer()->get(CompetitionRoundRepository::class)->get(CompetitionSeriesFixture::ROUND_EJJ_69);
        self::assertTrue(EventIsOnline::of($round->competition));
        self::assertSame(OnlineTimingAvailability::Available, OnlineTimingAvailability::ofRound($round));

        $this->database->executeStatement('UPDATE competition SET is_online = true WHERE id = :id', ['id' => CompetitionSeriesFixture::EDITION_EJJ_69]);
        $this->database->executeStatement('UPDATE competition_series SET is_online = false WHERE id = :id', ['id' => CompetitionSeriesFixture::SERIES_EJJ]);
        $this->clear();

        $round = self::getContainer()->get(CompetitionRoundRepository::class)->get(CompetitionSeriesFixture::ROUND_EJJ_69);
        self::assertFalse(EventIsOnline::of($round->competition));
        self::assertSame(OnlineTimingAvailability::InPersonEvent, OnlineTimingAvailability::ofRound($round));
        self::assertSame(
            'CASE WHEN c.series_id IS NULL THEN c.is_online ELSE cs.is_online END',
            EventIsOnline::sql('c', 'cs'),
        );
    }

    private function editRound(string $roundId, RoundCategory $category): void
    {
        $round = self::getContainer()->get(CompetitionRoundRepository::class)->get($roundId);

        $this->messageBus->dispatch(new EditCompetitionRound(
            roundId: $roundId,
            competitionId: self::COMPETITION,
            name: $round->name,
            minutesLimit: $round->minutesLimit,
            startsAt: $round->startsAt,
            timezone: 'Europe/Prague',
            badgeBackgroundColor: null,
            badgeTextColor: null,
            category: $category,
        ));
        $this->clear();
    }

    private function editCompetition(bool $isOnline): void
    {
        $competition = self::getContainer()->get(CompetitionRepository::class)->get(self::COMPETITION);

        $this->messageBus->dispatch(new EditCompetition(
            competitionId: self::COMPETITION,
            name: $competition->name,
            shortcut: $competition->shortcut,
            description: $competition->description,
            link: $competition->link,
            registrationLink: $competition->registrationLink,
            resultsLink: $competition->resultsLink,
            location: $isOnline ? null : 'Brno',
            locationCountryCode: $isOnline ? null : 'cz',
            dateFrom: $competition->dateFrom,
            dateTo: $competition->dateTo,
            isOnline: $isOnline,
            logo: null,
            maintainerIds: [],
            eligibility: null,
            eventTypes: null,
        ));
        $this->clear();
    }

    private function editSeries(bool $isOnline): void
    {
        $series = self::getContainer()->get(CompetitionSeriesRepository::class)->get(CompetitionSeriesFixture::SERIES_EJJ);

        $this->messageBus->dispatch(new EditCompetitionSeries(
            seriesId: CompetitionSeriesFixture::SERIES_EJJ,
            name: $series->name,
            shortcut: $series->shortcut,
            description: $series->description,
            link: $series->link,
            isOnline: $isOnline,
            location: $isOnline ? null : 'Brno',
            locationCountryCode: $isOnline ? null : 'cz',
            logo: null,
            maintainerIds: [],
            eligibility: null,
            schedule: null,
            eventTypes: null,
        ));
        $this->clear();
    }

    private function addRound(string $roundId): void
    {
        $this->database->executeStatement(
            "INSERT INTO competition_round (id, competition_id, name, minutes_limit, starts_at, slug, category)
             VALUES (:id, :competition, 'Second Round', 45, NOW() + INTERVAL '30 days', 'second-round', 'solo')",
            ['id' => $roundId, 'competition' => self::COMPETITION],
        );
    }

    /**
     * Online timing on a round of an online event that is no fixture's (the defaults, as EnableOnlineRound makes them)
     */
    private function useOnlineTiming(string $roundId): void
    {
        $this->database->executeStatement(
            "INSERT INTO online_round (id, round_id, enabled_at, enabled_by_id, detection_mode, video_enabled, participants_see_each_other, studio_enabled, recording_enabled, unfinished_measure, missing_piece_rule)
             VALUES ('018d0050-0000-0000-0000-0000000001f1', :round, NOW(), NULL, 'off', false, false, false, false, 'left', 'complete_required')",
            ['round' => $roundId],
        );
    }

    private function clear(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }
}
