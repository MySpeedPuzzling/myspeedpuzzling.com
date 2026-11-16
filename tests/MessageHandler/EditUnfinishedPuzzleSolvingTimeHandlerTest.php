<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\InvalidPiecesPlaced;
use SpeedPuzzling\Web\Exceptions\UnfinishedResultNeedsItsOwnEdit;
use SpeedPuzzling\Web\Exceptions\UnfinishedResultNeedsTimeLimit;
use SpeedPuzzling\Web\Exceptions\UnfinishedTotalTimeWithinLimit;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AddUnfinishedPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\EditUnfinishedPuzzleSolvingTime;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Services\RoundResults\SolvingTimeRoundResolver;
use SpeedPuzzling\Web\Services\SeriesEditions\SeriesEditionResolver;
use SpeedPuzzling\Web\Services\Xp\XpChainRecomputer;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Tests\TrackerlessTimeSeeding;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Editing an unfinished result and every transition (docs/features/competitions-management/unfinished-results-plan.md,
 * step 3): finished -> unfinished and unfinished -> unfinished through EditUnfinishedPuzzleSolvingTime, unfinished ->
 * finished / relax only through EditPuzzleSolvingTime with $endsUnfinishedResult - without it the old edit refuses, so
 * no edit surface can quietly turn an unfinished result into a relax solve or move it off its round.
 */
final class EditUnfinishedPuzzleSolvingTimeHandlerTest extends KernelTestCase
{
    use TrackerlessTimeSeeding;

    private const string USER = PlayerFixture::PLAYER_REGULAR_USER_ID;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAFinishedResultTurnsUnfinishedAndLosesItsXp(): void
    {
        // TIME_01: PLAYER_REGULAR, solo PUZZLE_500_01 in 30 min, no event
        $xp = self::getContainer()->get(XpChainRecomputer::class);
        $xp->awardForNewSolve(PuzzleSolvingTimeFixture::TIME_01);
        self::assertGreaterThan(0, $this->xpOf(PuzzleSolvingTimeFixture::TIME_01));

        $this->edit(PuzzleSolvingTimeFixture::TIME_01, piecesPlaced: 450, firstAttempt: true);

        self::assertSame([
            'seconds_to_solve' => null,
            'pieces_placed' => 450,
            'finished_later_seconds' => null,
            'seconds_spent' => 3600,
            'competition_id' => CompetitionFixture::COMPETITION_WJPC_2024,
            'competition_round_id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            'first_attempt' => true,
        ], $this->row(PuzzleSolvingTimeFixture::TIME_01));

        // An edit is delete + re-add for XP: the rebuild takes back what the finished result earned
        $xp->rebuildChainForEditedSolve(PuzzleSolvingTimeFixture::TIME_01);
        self::assertSame(0, $this->xpOf(PuzzleSolvingTimeFixture::TIME_01));
    }

    public function testAnUnfinishedResultChangesItsPiecesTotalAndEvent(): void
    {
        $timeId = $this->addUnfinished();

        // The Czech nationals' final: 90 min, PUZZLE_500_01
        $this->edit($timeId, piecesPlaced: 498, totalTime: '01:40:00', competitionId: CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, comment: 'Almost');

        self::assertSame([
            'seconds_to_solve' => null,
            'pieces_placed' => 498,
            'finished_later_seconds' => 6000,
            'seconds_spent' => 6000,
            'competition_id' => CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024,
            'competition_round_id' => CompetitionRoundFixture::ROUND_CZECH_FINAL,
            'first_attempt' => false,
        ], $this->row($timeId));
    }

    public function testAnotherPuzzleWithFewerPiecesIsRefusedAndNothingChanges(): void
    {
        $timeId = $this->addUnfinished();
        $before = $this->row($timeId);

        // PUZZLE_1000_01 is in the WJPC final (120 min) - but 1000 pieces would allow 450; a 300-piece puzzle has no round
        $this->assertRefused(UnfinishedResultNeedsTimeLimit::class, fn () => $this->edit($timeId, puzzleId: PuzzleFixture::PUZZLE_300));
        $this->assertRefused(InvalidPiecesPlaced::class, fn () => $this->edit($timeId, piecesPlaced: 500));
        $this->assertRefused(UnfinishedTotalTimeWithinLimit::class, fn () => $this->edit($timeId, totalTime: '00:59:59'));

        self::assertSame($before, $this->row($timeId));
    }

    public function testMovedToAnotherPuzzleOfTheEventItFollowsItsRound(): void
    {
        $timeId = $this->addUnfinished();

        $this->edit($timeId, puzzleId: PuzzleFixture::PUZZLE_1000_01, piecesPlaced: 800);

        $row = $this->row($timeId);
        self::assertSame(CompetitionRoundFixture::ROUND_WJPC_FINAL, $row['competition_round_id']);
        self::assertSame(7200, $row['seconds_spent']);
        self::assertSame(800, $row['pieces_placed']);
    }

    public function testBecomingAPairWithoutAPairsRoundIsRefused(): void
    {
        $timeId = $this->addUnfinished();
        $before = $this->row($timeId);

        $this->assertRefused(UnfinishedResultNeedsTimeLimit::class, fn () => $this->edit($timeId, groupPlayers: ['Anna Guest']));
        $this->assertRefused(UnfinishedResultNeedsTimeLimit::class, fn () => $this->edit($timeId, competitionId: null));

        self::assertSame($before, $this->row($timeId));
    }

    public function testTheOldEditRefusesAnUnfinishedResult(): void
    {
        $timeId = $this->addUnfinished();
        $before = $this->row($timeId);

        // With a time (the edit form's Speed mode, the API) and without one (relax, My data's row) alike
        $this->assertRefused(UnfinishedResultNeedsItsOwnEdit::class, fn () => $this->finishedEdit($timeId, time: '00:45:00'));
        $this->assertRefused(UnfinishedResultNeedsItsOwnEdit::class, fn () => $this->finishedEdit($timeId, time: null));

        self::assertSame($before, $this->row($timeId));
    }

    public function testAnUnfinishedResultBecomesFinishedWhenTheEditSaysSo(): void
    {
        $timeId = $this->addUnfinished();

        $this->finishedEdit($timeId, time: '00:58:00', endsUnfinishedResult: true);

        self::assertSame([
            'seconds_to_solve' => 3480,
            'pieces_placed' => null,
            'finished_later_seconds' => null,
            'seconds_spent' => null,
            'competition_id' => CompetitionFixture::COMPETITION_WJPC_2024,
            'competition_round_id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            'first_attempt' => false,
        ], $this->row($timeId));
    }

    public function testAnUnfinishedResultBecomesARelaxSolveWhenTheEditSaysSo(): void
    {
        $timeId = $this->addUnfinished(totalTime: '01:30:00');

        $this->finishedEdit($timeId, time: null, endsUnfinishedResult: true, competitionId: null);

        $row = $this->row($timeId);
        self::assertNull($row['seconds_to_solve']);
        self::assertNull($row['pieces_placed']);
        self::assertNull($row['finished_later_seconds']);
        self::assertNull($row['seconds_spent']);
        self::assertNull($row['competition_round_id']);
    }

    public function testAFinishedEditMarkedAsEndingIsHarmlessOnAFinishedResult(): void
    {
        $this->finishedEdit(PuzzleSolvingTimeFixture::TIME_01, time: '00:31:00', endsUnfinishedResult: true);

        self::assertSame(1860, $this->row(PuzzleSolvingTimeFixture::TIME_01)['seconds_to_solve']);
    }

    public function testASeriesPickPairIsResolvedAsTheSaveWouldResolveIt(): void
    {
        $scenario = new SeriesEditionScenario(self::getContainer());
        $series = $scenario->series();
        $puzzle = $scenario->puzzle();
        $edition = $scenario->edition($series, 'Jam No. 31', '2026-03-02');
        $round = $scenario->round($edition, RoundCategory::Duo, '2026-03-02 19:00', puzzleIds: [$puzzle]);

        // A finished solo time of the puzzle turns out an unfinished pair's result of the series' edition
        $timeId = Uuid::uuid7();
        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: self::USER,
            puzzleId: $puzzle,
            competitionId: null,
            time: '01:30:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: new DateTimeImmutable('2026-03-02'),
            firstAttempt: false,
            unboxed: false,
        ));

        $this->messageBus->dispatch(new EditUnfinishedPuzzleSolvingTime(
            currentUserId: self::USER,
            puzzleSolvingTimeId: $timeId->toString(),
            competitionId: null,
            piecesPlaced: 400,
            totalTime: null,
            comment: null,
            groupPlayers: ['Anna Guest'],
            finishedAt: new DateTimeImmutable('2026-03-02'),
            finishedPuzzlesPhoto: null,
            firstAttempt: false,
            unboxed: false,
            seriesId: $series,
        ));

        self::assertSame(
            ['competition_id' => $edition, 'competition_series_id' => $series, 'series_edition_match' => 'puzzle', 'competition_round_id' => $round],
            $scenario->link($timeId->toString()),
        );

        $stored = self::getContainer()->get(PuzzleSolvingTimeRepository::class)->get($timeId->toString());
        self::assertSame($edition, self::getContainer()->get(SeriesEditionResolver::class)->resolve($stored)->competitionId);
        self::assertSame($round, self::getContainer()->get(SolvingTimeRoundResolver::class)->resolve($stored)?->id->toString());
        self::assertSame(7200, $stored->secondsSpent);
        self::assertNotNull($stored->puzzlingTeam);
    }

    public function testAnImportedEventResultIsNeverEditedHere(): void
    {
        $round = $this->seedTrackerlessEvent(PuzzleFixture::PUZZLE_500_02);
        $timeId = $this->seedTrackerlessTime($round, PuzzleFixture::PUZZLE_500_02, 'Puzzle Pals', 3600)->id->toString();

        $this->assertRefused(CanNotModifyOtherPlayersTime::class, fn () => $this->edit($timeId));
    }

    private function addUnfinished(null|string $totalTime = null): string
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddUnfinishedPuzzleSolvingTime(
            timeId: $timeId,
            userId: self::USER,
            puzzleId: PuzzleFixture::PUZZLE_500_01,
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            piecesPlaced: 450,
            totalTime: $totalTime,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: new DateTimeImmutable('2026-03-02'),
            firstAttempt: false,
            unboxed: false,
        ));

        return $timeId->toString();
    }

    /**
     * @param array<string> $groupPlayers
     */
    private function edit(
        string $timeId,
        int $piecesPlaced = 450,
        null|string $totalTime = null,
        null|string $competitionId = CompetitionFixture::COMPETITION_WJPC_2024,
        null|string $puzzleId = null,
        array $groupPlayers = [],
        bool $firstAttempt = false,
        null|string $comment = null,
    ): void {
        $this->messageBus->dispatch(new EditUnfinishedPuzzleSolvingTime(
            currentUserId: self::USER,
            puzzleSolvingTimeId: $timeId,
            competitionId: $competitionId,
            piecesPlaced: $piecesPlaced,
            totalTime: $totalTime,
            comment: $comment,
            groupPlayers: $groupPlayers,
            finishedAt: new DateTimeImmutable('2026-03-02'),
            finishedPuzzlesPhoto: null,
            firstAttempt: $firstAttempt,
            unboxed: false,
            puzzleId: $puzzleId,
        ));
    }

    private function finishedEdit(string $timeId, null|string $time, bool $endsUnfinishedResult = false, null|string $competitionId = CompetitionFixture::COMPETITION_WJPC_2024): void
    {
        $this->messageBus->dispatch(new EditPuzzleSolvingTime(
            currentUserId: self::USER,
            puzzleSolvingTimeId: $timeId,
            competitionId: $competitionId,
            time: $time,
            comment: null,
            groupPlayers: [],
            finishedAt: new DateTimeImmutable('2026-03-02'),
            finishedPuzzlesPhoto: null,
            firstAttempt: false,
            unboxed: false,
            endsUnfinishedResult: $endsUnfinishedResult,
        ));
    }

    /**
     * @param class-string<Throwable> $expected
     */
    private function assertRefused(string $expected, callable $edit): void
    {
        try {
            $edit();
            self::fail('Expected ' . $expected);
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf($expected, $exception->getPrevious());
        } catch (Throwable $exception) {
            // An HTTP exception (403) comes unwrapped (UnwrapHttpExceptionMiddleware)
            self::assertInstanceOf($expected, $exception);
        }
    }

    private function xpOf(string $timeId): int
    {
        $sum = $this->database->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM xp_entry WHERE solving_time_id = :id', ['id' => $timeId]);
        self::assertIsInt($sum);

        return $sum;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $timeId): array
    {
        $row = $this->database->fetchAssociative(
            'SELECT seconds_to_solve, pieces_placed, finished_later_seconds, seconds_spent, competition_id, competition_round_id, first_attempt
            FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId],
        );
        self::assertIsArray($row);

        return $row;
    }
}
