<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\InvalidPiecesPlaced;
use SpeedPuzzling\Web\Exceptions\SolvingTimeAlreadySaved;
use SpeedPuzzling\Web\Exceptions\SolvingTimeIdReused;
use SpeedPuzzling\Web\Exceptions\SuspiciousPpm;
use SpeedPuzzling\Web\Exceptions\UnfinishedResultNeedsTimeLimit;
use SpeedPuzzling\Web\Exceptions\UnfinishedTotalTimeWithinLimit;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\AddUnfinishedPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AwardXpForSolvingTime;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\SetCompetitionRoundPuzzles;
use SpeedPuzzling\Web\Services\RoundResults\SolvingTimeRoundResolver;
use SpeedPuzzling\Web\Services\Xp\XpChainRecomputer;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Services\SeriesEditions\SeriesEditionResolver;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundTimezone;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Throwable;

/**
 * Adding an unfinished result (docs/features/competitions-management/unfinished-results-plan.md, step 3): the round
 * with a time limit is required, the pieces and the total checked by UnfinishedResult, nothing stored when refused, and
 * nothing awarded for it (not a solve).
 */
final class AddUnfinishedPuzzleSolvingTimeHandlerTest extends KernelTestCase
{
    private const string USER = PlayerFixture::PLAYER_REGULAR_USER_ID;

    private MessageBusInterface $messageBus;
    private Connection $database;
    private SeriesEditionScenario $scenario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->scenario = new SeriesEditionScenario(self::getContainer());
    }

    public function testASoloUnfinishedResultIsStoredWithItsRoundAndTheLimitAsTimeSpent(): void
    {
        $timeId = $this->add(piecesPlaced: 479, comment: 'Ran out of time', unboxed: true);

        self::assertSame([
            'seconds_to_solve' => null,
            'pieces_placed' => 479,
            'finished_later_seconds' => null,
            'seconds_spent' => 3600,
            'player_id' => PlayerFixture::PLAYER_REGULAR,
            'puzzle_id' => PuzzleFixture::PUZZLE_500_02,
            'competition_id' => CompetitionFixture::COMPETITION_WJPC_2024,
            'competition_round_id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            'competition_series_id' => null,
            'puzzling_type' => 'solo',
            'comment' => 'Ran out of time',
            'first_attempt' => false,
            'unboxed' => true,
            'created_via' => 'form',
        ], $this->row($timeId));
    }

    public function testATotalOverTheLimitIsTheLaterFinishAndTheTimeSpent(): void
    {
        $timeId = $this->add(totalTime: '01:12:30');

        $row = $this->row($timeId);
        self::assertSame(4350, $row['finished_later_seconds']);
        self::assertSame(4350, $row['seconds_spent']);
        self::assertNull($row['seconds_to_solve']);
    }

    public function testATotalWithinTheLimitIsRefused(): void
    {
        $this->assertRefused(UnfinishedTotalTimeWithinLimit::class, fn (UuidInterface $id) => $this->add(id: $id, totalTime: '01:00:00'));
    }

    public function testAllPiecesPlacedIsRefused(): void
    {
        $this->assertRefused(InvalidPiecesPlaced::class, fn (UuidInterface $id) => $this->add(id: $id, piecesPlaced: 500));
    }

    public function testNoPiecesPlacedIsRefused(): void
    {
        $this->assertRefused(InvalidPiecesPlaced::class, fn (UuidInterface $id) => $this->add(id: $id, piecesPlaced: 0));
    }

    public function testWithoutAnEventItIsRefused(): void
    {
        $this->assertRefused(UnfinishedResultNeedsTimeLimit::class, fn (UuidInterface $id) => $this->add(id: $id, competitionId: null));
    }

    public function testAnEventWhoseRoundsDoNotHaveThePuzzleIsRefused(): void
    {
        // The Czech nationals' only round has PUZZLE_500_01
        $this->assertRefused(UnfinishedResultNeedsTimeLimit::class, fn (UuidInterface $id) => $this->add(id: $id, competitionId: CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024));
    }

    public function testAnUnknownEventIsRefusedNotSavedWithoutIt(): void
    {
        $this->assertRefused(UnfinishedResultNeedsTimeLimit::class, fn (UuidInterface $id) => $this->add(id: $id, competitionId: Uuid::uuid7()->toString()));
    }

    public function testAPairOnASoloRoundIsRefused(): void
    {
        $this->assertRefused(UnfinishedResultNeedsTimeLimit::class, fn (UuidInterface $id) => $this->add(id: $id, groupPlayers: ['Anna Guest']));
    }

    public function testARoundWithoutATimeLimitIsRefused(): void
    {
        $series = $this->scenario->series();
        $edition = $this->scenario->edition($series, 'Jam No. 7', '2026-03-02');
        $roundId = Uuid::uuid7();
        $this->scenario->dispatch(new AddCompetitionRound(
            roundId: $roundId,
            competitionId: $edition,
            name: 'No limit',
            minutesLimit: null,
            startsAt: RoundTimezone::toInstant('2026-03-02 19:00', 'Europe/Berlin'),
            timezone: 'Europe/Berlin',
            badgeBackgroundColor: null,
            badgeTextColor: null,
            category: RoundCategory::Solo,
        ));
        $this->scenario->dispatch(new SetCompetitionRoundPuzzles($roundId->toString(), [PuzzleFixture::PUZZLE_500_02]));

        $this->assertRefused(UnfinishedResultNeedsTimeLimit::class, fn (UuidInterface $id) => $this->add(id: $id, competitionId: $edition));
    }

    public function testAPairOnAPairsRoundGetsItsTeamAndRound(): void
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $edition = $this->scenario->edition($series, 'Jam No. 8', '2026-03-02');
        $round = $this->scenario->round($edition, RoundCategory::Duo, '2026-03-02 19:00', puzzleIds: [$puzzle]);

        $timeId = $this->add(puzzleId: $puzzle, competitionId: $edition, groupPlayers: ['Anna Guest'], piecesPlaced: 420, finishedAt: '2026-03-02');

        $row = $this->row($timeId);
        self::assertSame($round, $row['competition_round_id']);
        self::assertSame('duo', $row['puzzling_type']);
        self::assertSame(7200, $row['seconds_spent']);
        self::assertNotNull($this->database->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));
    }

    public function testAnEmptyGroupIsRefusedLikeOnTheFinishedPath(): void
    {
        $this->assertRefused(CanNotAssembleEmptyGroup::class, fn (UuidInterface $id) => $this->add(id: $id, onlySelf: true));
    }

    public function testAnImpossiblePaceOfTheTotalIsRefused(): void
    {
        // A 500-piece puzzle in a 1-minute round, finished 1 second later: 500 pieces a minute
        $series = $this->scenario->series();
        $edition = $this->scenario->edition($series, 'Jam No. 9', '2026-03-02');
        $roundId = Uuid::uuid7();
        $this->scenario->dispatch(new AddCompetitionRound(
            roundId: $roundId,
            competitionId: $edition,
            name: 'Sprint',
            minutesLimit: 1,
            startsAt: RoundTimezone::toInstant('2026-03-02 19:00', 'Europe/Berlin'),
            timezone: 'Europe/Berlin',
            badgeBackgroundColor: null,
            badgeTextColor: null,
            category: RoundCategory::Solo,
        ));
        $this->scenario->dispatch(new SetCompetitionRoundPuzzles($roundId->toString(), [PuzzleFixture::PUZZLE_500_02]));

        $this->assertRefused(SuspiciousPpm::class, fn (UuidInterface $id) => $this->add(id: $id, competitionId: $edition, totalTime: '00:01:01'));
    }

    public function testTheApiRoundIdLinksItsCompetitionAndRound(): void
    {
        $timeId = $this->add(competitionId: null, roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, createdVia: SolvingTimeSource::Api);

        $row = $this->row($timeId);
        self::assertSame(CompetitionFixture::COMPETITION_WJPC_2024, $row['competition_id']);
        self::assertSame(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, $row['competition_round_id']);
        self::assertSame('api', $row['created_via']);
    }

    public function testASeriesPickMatchedByItsPuzzleGetsTheEditionAndItsRound(): void
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $edition = $this->scenario->edition($series, 'Jam No. 153', '2026-03-02');
        $round = $this->scenario->round($edition, RoundCategory::Solo, '2026-03-02 19:00', puzzleIds: [$puzzle]);

        $timeId = $this->add(puzzleId: $puzzle, competitionId: null, seriesId: $series, finishedAt: '2026-03-03');

        self::assertSame(
            ['competition_id' => $edition, 'competition_series_id' => $series, 'series_edition_match' => 'puzzle', 'competition_round_id' => $round],
            $this->scenario->link($timeId),
        );

        // What the save's own resolvers answer for the stored result - the pre-resolved edition and round are theirs
        $stored = self::getContainer()->get(PuzzleSolvingTimeRepository::class)->get($timeId);
        self::assertSame($edition, self::getContainer()->get(SeriesEditionResolver::class)->resolve($stored)->competitionId);
        self::assertSame($round, self::getContainer()->get(SolvingTimeRoundResolver::class)->resolve($stored)?->id->toString());
    }

    public function testASeriesPickMatchedOnlyByTheDayIsRefused(): void
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $this->scenario->edition($series, 'Jam No. 154', '2026-03-02');

        $this->assertRefused(UnfinishedResultNeedsTimeLimit::class, fn (UuidInterface $id) => $this->add(id: $id, puzzleId: $puzzle, competitionId: null, seriesId: $series, finishedAt: '2026-03-02'));
    }

    public function testASeriesThatIsNotPublicIsRefused(): void
    {
        $series = $this->scenario->series(public: false);

        $this->assertRefused(UnfinishedResultNeedsTimeLimit::class, fn (UuidInterface $id) => $this->add(id: $id, competitionId: null, seriesId: $series));
    }

    public function testTheSameSaveSentAgainAnswersTheSavedResult(): void
    {
        $timeId = Uuid::uuid7();
        $this->add(id: $timeId);

        try {
            $this->add(id: $timeId);
            self::fail('Expected SolvingTimeAlreadySaved');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(SolvingTimeAlreadySaved::class, $exception->getPrevious());
        }

        try {
            $this->add(id: $timeId, piecesPlaced: 300);
            self::fail('Expected SolvingTimeIdReused');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(SolvingTimeIdReused::class, $exception->getPrevious());
        }
    }

    public function testNothingIsAwardedForIt(): void
    {
        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $async->reset();

        $timeId = $this->add();

        // The same follow-ups as a finished result are queued...
        $sent = array_map(static fn ($envelope): string => $envelope->getMessage()::class, $async->getSent());
        self::assertContains(AwardXpForSolvingTime::class, $sent);
        self::assertContains(RecalculateBadgesForPlayer::class, $sent);

        // ...and award nothing: an unfinished result is not a solve
        self::getContainer()->get(XpChainRecomputer::class)->awardForNewSolve($timeId);

        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM xp_entry WHERE solving_time_id = :id', ['id' => $timeId]));
    }

    /**
     * @param array<string> $groupPlayers
     */
    private function add(
        null|UuidInterface $id = null,
        string $puzzleId = PuzzleFixture::PUZZLE_500_02,
        null|string $competitionId = CompetitionFixture::COMPETITION_WJPC_2024,
        int $piecesPlaced = 479,
        null|string $totalTime = null,
        null|string $comment = null,
        array $groupPlayers = [],
        bool $firstAttempt = false,
        bool $unboxed = false,
        null|string $roundId = null,
        null|string $seriesId = null,
        string $finishedAt = '2026-03-02',
        SolvingTimeSource $createdVia = SolvingTimeSource::Form,
        string $userId = self::USER,
        bool $onlySelf = false,
    ): string {
        $id ??= Uuid::uuid7();

        if ($onlySelf) {
            // The player's own code - the group would be only the player
            $code = $this->database->fetchOne('SELECT code FROM player WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);
            self::assertIsString($code);
            $groupPlayers = ['#' . $code];
        }

        $this->messageBus->dispatch(new AddUnfinishedPuzzleSolvingTime(
            timeId: $id,
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: $competitionId,
            piecesPlaced: $piecesPlaced,
            totalTime: $totalTime,
            comment: $comment,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: new DateTimeImmutable($finishedAt),
            firstAttempt: $firstAttempt,
            unboxed: $unboxed,
            roundId: $roundId,
            createdVia: $createdVia,
            seriesId: $seriesId,
        ));

        return $id->toString();
    }

    /**
     * @param class-string<Throwable> $expected
     * @param callable(UuidInterface): mixed $add
     */
    private function assertRefused(string $expected, callable $add): void
    {
        $id = Uuid::uuid7();

        try {
            $add($id);
            self::fail('Expected ' . $expected);
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf($expected, $exception->getPrevious());
        }

        self::assertFalse($this->database->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE id = :id', ['id' => $id->toString()]));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $timeId): array
    {
        $row = $this->database->fetchAssociative(
            'SELECT seconds_to_solve, pieces_placed, finished_later_seconds, seconds_spent, player_id, puzzle_id, competition_id,
                competition_round_id, competition_series_id, puzzling_type, comment, first_attempt, unboxed, created_via
            FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId],
        );
        self::assertIsArray($row);

        return $row;
    }
}
