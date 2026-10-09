<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleInTwoRoundsOfCategory;
use SpeedPuzzling\Web\Exceptions\RoundMovedMeanwhile;
use SpeedPuzzling\Web\Exceptions\RoundNotMovable;
use SpeedPuzzling\Web\Message\AddCompetition;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\AddCompetitionRoundWithPuzzles;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AddTableRow;
use SpeedPuzzling\Web\Message\MoveRoundToCompetition;
use SpeedPuzzling\Web\Message\StartRoundStopwatch;
use SpeedPuzzling\Web\Query\GetEventUrlRedirect;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\EventUrlPath;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundNotMovableReason;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/organizations/README.md "Restructuring tools", D7: a round moves with its puzzles, its table layout and
 * every solving time that belongs to it - the times keep their round, both competitions are reconciled; a refusal
 * changes nothing; a taken slug gets a suffix; the round keeps its wall-clock zone (P22).
 */
final class MoveRoundToCompetitionHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheRoundMovesWithItsPuzzlesTablesAndTimes(): void
    {
        $source = $this->createEvent('Granite Falls Puzzle Open', 'granite-falls-puzzle-open', 'cz');
        $target = $this->createEvent('Granite Falls Puzzle Open - Finals', 'granite-falls-finals', 'cz');
        $roundId = $this->createRound($source, 'Main Final', [PuzzleFixture::PUZZLE_500_03]);
        $this->messageBus->dispatch(new AddTableRow(Uuid::uuid7(), $roundId));

        $linked = $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_500_03, $source, '00:45:10');
        $notLinkedYet = $this->addTime(PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID, PuzzleFixture::PUZZLE_500_03, $source, '00:50:20');
        // Another puzzle of the event - not the round's, it stays
        $other = $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, PuzzleFixture::PUZZLE_500_04, $source, '00:55:30');

        self::assertSame($roundId, $this->roundOf($linked));
        // A time of the round not linked yet (the derived rule says it is the round's) moves too
        $this->database->executeStatement('UPDATE puzzle_solving_time SET competition_round_id = NULL WHERE id = :id', ['id' => $notLinkedYet]);
        $this->clearEntityManager();

        $this->move($roundId, $source, $target);

        self::assertSame($target, $this->database->fetchOne('SELECT competition_id FROM competition_round WHERE id = :id', ['id' => $roundId]));
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM competition_round_puzzle WHERE round_id = :id', ['id' => $roundId]));
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM table_row WHERE round_id = :id', ['id' => $roundId]));

        foreach ([$linked, $notLinkedYet] as $timeId) {
            self::assertSame($target, $this->competitionOf($timeId));
            self::assertSame($roundId, $this->roundOf($timeId));
        }

        self::assertSame($source, $this->competitionOf($other));
        self::assertNull($this->roundOf($other));

        self::assertSame(
            ['route' => 'event_round_results', 'params' => ['slug' => 'granite-falls-finals', 'roundSlug' => 'main-final']],
            self::getContainer()->get(GetEventUrlRedirect::class)->target(EventUrlPath::eventRound('granite-falls-puzzle-open', 'main-final')),
        );
    }

    public function testATakenSlugGetsASuffixAndTheZoneIsKept(): void
    {
        $source = $this->createEvent('Basalt Ridge Puzzle Day', 'basalt-ridge-puzzle-day', 'cz');
        $target = $this->createEvent('Basalt Ridge Puzzle Night', 'basalt-ridge-puzzle-night', 'us');
        $roundId = $this->createRound($source, 'Final', [PuzzleFixture::PUZZLE_500_03]);
        $this->createRound($target, 'Final', []);
        // A round saved before rounds kept their zone - shown in the event's country's zone
        $this->database->executeStatement('UPDATE competition_round SET timezone = NULL WHERE id = :id', ['id' => $roundId]);
        $this->clearEntityManager();

        $this->move($roundId, $source, $target);

        /** @var array{slug: string, timezone: string} $round */
        $round = $this->database->fetchAssociative('SELECT slug, timezone FROM competition_round WHERE id = :id', ['id' => $roundId]);
        self::assertSame('final-2', $round['slug']);
        self::assertSame('Europe/Prague', $round['timezone']);
    }

    public function testThePuzzleInARoundOfTheSameCategoryThereIsRefused(): void
    {
        $source = $this->createEvent('Slate Hill Puzzle Cup', 'slate-hill-puzzle-cup', 'cz');
        $target = $this->createEvent('Slate Hill Puzzle Cup Two', 'slate-hill-puzzle-cup-two', 'cz');
        $roundId = $this->createRound($source, 'Qualifier', [PuzzleFixture::PUZZLE_500_03]);
        $this->createRound($target, 'Semifinal', [PuzzleFixture::PUZZLE_500_03]);

        try {
            $this->move($roundId, $source, $target);
            self::fail('The one-round-per-category invariant must refuse the move');
        } catch (PuzzleInTwoRoundsOfCategory $exception) {
            self::assertStringContainsString('Semifinal', $exception->getMessage());
        }

        self::assertSame($source, $this->database->fetchOne('SELECT competition_id FROM competition_round WHERE id = :id', ['id' => $roundId]));
    }

    public function testARoundWithEntriesStays(): void
    {
        try {
            $this->move(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, CompetitionFixture::COMPETITION_WJPC_2024, CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024);
            self::fail('A round with participants entered must not move');
        } catch (RoundNotMovable $exception) {
            self::assertSame(RoundNotMovableReason::HasEntries, $exception->reason);
        }

        self::assertSame(
            CompetitionFixture::COMPETITION_WJPC_2024,
            $this->database->fetchOne('SELECT competition_id FROM competition_round WHERE id = :id', ['id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION]),
        );
    }

    public function testARunningStopwatchAndTheSameCompetitionAreRefused(): void
    {
        $source = $this->createEvent('Marble Bay Puzzle Race', 'marble-bay-puzzle-race', 'cz');
        $target = $this->createEvent('Marble Bay Puzzle Race Two', 'marble-bay-puzzle-race-two', 'cz');
        $roundId = $this->createRound($source, 'Race', []);

        try {
            $this->move($roundId, $source, $source);
            self::fail('The same competition must be refused');
        } catch (RoundNotMovable $exception) {
            self::assertSame(RoundNotMovableReason::SameCompetition, $exception->reason);
        }

        $this->messageBus->dispatch(new StartRoundStopwatch($roundId));

        try {
            $this->move($roundId, $source, $target);
            self::fail('A running stopwatch must be refused');
        } catch (RoundNotMovable $exception) {
            self::assertSame(RoundNotMovableReason::StopwatchRunning, $exception->reason);
        }
    }

    public function testADraftTakesOnlyARoundWithoutResults(): void
    {
        $source = $this->createEvent('Cobble Lane Puzzle Day', 'cobble-lane-puzzle-day', 'cz');
        $withResults = $this->createRound($source, 'Morning', [PuzzleFixture::PUZZLE_500_03]);
        $empty = $this->createRound($source, 'Evening', [PuzzleFixture::PUZZLE_500_04]);
        $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_500_03, $source, '00:47:00');

        try {
            $this->move($withResults, $source, OrganizationFixture::COMPETITION_DRAFT_NIGHT);
            self::fail('A draft must never get linked times');
        } catch (RoundNotMovable $exception) {
            self::assertSame(RoundNotMovableReason::DraftTargetWithResults, $exception->reason);
        }

        self::assertSame($source, $this->database->fetchOne('SELECT competition_id FROM competition_round WHERE id = :id', ['id' => $withResults]));

        // An edition of a draft series is hidden as a draft too
        try {
            $this->move($withResults, $source, OrganizationFixture::EDITION_QUIET_PINES_1);
            self::fail('An edition of a draft series must never get linked times');
        } catch (RoundNotMovable $exception) {
            self::assertSame(RoundNotMovableReason::DraftTargetWithResults, $exception->reason);
        }

        $this->move($empty, $source, OrganizationFixture::COMPETITION_DRAFT_NIGHT);
        self::assertSame(OrganizationFixture::COMPETITION_DRAFT_NIGHT, $this->database->fetchOne('SELECT competition_id FROM competition_round WHERE id = :id', ['id' => $empty]));
    }

    public function testARoundThatMovedMeanwhileIsRefused(): void
    {
        $source = $this->createEvent('Flint Creek Puzzle Day', 'flint-creek-puzzle-day', 'cz');
        $target = $this->createEvent('Flint Creek Puzzle Night', 'flint-creek-puzzle-night', 'cz');
        $roundId = $this->createRound($source, 'Main', []);

        $this->expectException(RoundMovedMeanwhile::class);

        // Asked for with the competition it was in before
        $this->move($roundId, $target, $source);
    }

    /**
     * P29 (docs/features/events-page/high-frequency-series.md): explicit times move with the round; a series pick's
     * edition follows the matching rule, not the round - out of the series, it is series-level again, never linked to
     * the one-time event the round went to.
     */
    public function testASeriesPickDoesNotMoveWithTheRoundButIsMatchedAgain(): void
    {
        $scenario = new SeriesEditionScenario(self::getContainer());
        $series = $scenario->series();
        $puzzle = $scenario->puzzle();
        $edition = $scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $roundId = $scenario->round($edition, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$puzzle]);
        $explicit = $scenario->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, $puzzle, '2026-03-02', competitionId: $edition);
        $pick = $scenario->addTime(PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID, $puzzle, '2026-03-10', seriesId: $series);
        self::assertSame($edition, $this->competitionOf($pick));
        self::assertSame($roundId, $this->roundOf($pick));
        $target = $this->createEvent('Granite Falls Puzzle Open', 'granite-falls-puzzle-open', 'cz');

        $this->move($roundId, $edition, $target);

        self::assertSame($target, $this->competitionOf($explicit));
        self::assertSame($roundId, $this->roundOf($explicit));
        self::assertSame(
            ['competition_id' => null, 'competition_series_id' => $series, 'series_edition_match' => null, 'competition_round_id' => null],
            $scenario->link($pick),
        );
    }

    private function createEvent(string $name, string $slug, string $countryCode): string
    {
        $competitionId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddCompetition(
            competitionId: $competitionId,
            playerId: PlayerFixture::PLAYER_ADMIN,
            name: $name,
            shortcut: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: 'Granite Falls',
            locationCountryCode: $countryCode,
            dateFrom: new DateTimeImmutable('2026-05-02'),
            dateTo: new DateTimeImmutable('2026-05-02'),
            isOnline: false,
            logo: null,
            maintainerIds: [],
            slug: $slug,
            notifyAdmin: false,
        ));

        return $competitionId->toString();
    }

    /**
     * @param list<string> $puzzleIds
     */
    private function createRound(string $competitionId, string $name, array $puzzleIds): string
    {
        $roundId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddCompetitionRoundWithPuzzles(new AddCompetitionRound(
            roundId: $roundId,
            competitionId: $competitionId,
            name: $name,
            minutesLimit: 90,
            startsAt: new DateTimeImmutable('2026-05-02 08:00:00'),
            timezone: 'Europe/Prague',
            badgeBackgroundColor: null,
            badgeTextColor: null,
        ), $puzzleIds));

        return $roundId->toString();
    }

    private function addTime(string $userId, string $puzzleId, string $competitionId, string $time): string
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: $competitionId,
            time: $time,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));

        return $timeId->toString();
    }

    private function move(string $roundId, string $competitionId, string $targetId): void
    {
        $this->messageBus->dispatch(new MoveRoundToCompetition(
            roundId: $roundId,
            competitionId: $competitionId,
            targetCompetitionId: $targetId,
            actingPlayerId: PlayerFixture::PLAYER_ADMIN,
        ));
    }

    private function clearEntityManager(): void
    {
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();
    }

    private function competitionOf(string $timeId): mixed
    {
        return $this->database->fetchOne('SELECT competition_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
    }

    private function roundOf(string $timeId): mixed
    {
        return $this->database->fetchOne('SELECT competition_round_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
    }
}
