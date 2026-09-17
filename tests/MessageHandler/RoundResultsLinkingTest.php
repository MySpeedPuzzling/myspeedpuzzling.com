<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRoundCategory;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\BackfillCompetitionRoundSlugs;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCompetitionRound;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The round a solving time belongs to follows from competition + puzzle + solo/duo/team, through every write
 * path: the time's own add/edit (resolved before flush) and round-side changes (reconciled on postFlush).
 */
final class RoundResultsLinkingTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAddedTimeIsLinkedToTheRoundOfItsPuzzle(): void
    {
        $timeId = $this->addTime(PuzzleFixture::PUZZLE_500_02, CompetitionFixture::COMPETITION_WJPC_2024);

        self::assertSame(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, $this->roundOf($timeId));
    }

    public function testAddedTimeWithoutCompetitionOrOnAnotherPuzzleHasNoRound(): void
    {
        self::assertNull($this->roundOf($this->addTime(PuzzleFixture::PUZZLE_500_02, null)));
        self::assertNull($this->roundOf($this->addTime(PuzzleFixture::PUZZLE_500_03, CompetitionFixture::COMPETITION_WJPC_2024)));
    }

    public function testAddedDuoTimeDoesNotJoinASoloRound(): void
    {
        $timeId = $this->addTime(PuzzleFixture::PUZZLE_500_02, CompetitionFixture::COMPETITION_WJPC_2024, groupPlayers: [PlayerFixture::PLAYER_ADMIN]);

        self::assertNull($this->roundOf($timeId));
    }

    public function testEditingAwayTheCompetitionUnlinksTheTime(): void
    {
        self::assertNotNull($this->roundOf(PuzzleSolvingTimeFixture::TIME_09));

        $this->messageBus->dispatch(new EditPuzzleSolvingTime(
            currentUserId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleSolvingTimeId: PuzzleSolvingTimeFixture::TIME_09,
            competitionId: null,
            time: '00:30:50',
            comment: null,
            groupPlayers: [],
            finishedAt: null,
            finishedPuzzlesPhoto: null,
            firstAttempt: true,
            unboxed: false,
        ));

        self::assertNull($this->roundOf(PuzzleSolvingTimeFixture::TIME_09));
    }

    public function testAttachingAPuzzleToARoundLinksItsExistingTimes(): void
    {
        $timeId = $this->addTime(PuzzleFixture::PUZZLE_500_03, CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertNull($this->roundOf($timeId));

        $this->messageBus->dispatch($this->attachPuzzle(CompetitionRoundFixture::ROUND_WJPC_FINAL, PuzzleFixture::PUZZLE_500_03));

        self::assertSame(CompetitionRoundFixture::ROUND_WJPC_FINAL, $this->roundOf($timeId));
    }

    public function testRemovingAPuzzleFromARoundUnlinksItsTimes(): void
    {
        $roundPuzzleId = $this->database->fetchOne(
            'SELECT id FROM competition_round_puzzle WHERE round_id = :round AND puzzle_id = :puzzle',
            ['round' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, 'puzzle' => PuzzleFixture::PUZZLE_500_01],
        );
        self::assertIsString($roundPuzzleId);

        $this->messageBus->dispatch(new RemovePuzzleFromCompetitionRound($roundPuzzleId));

        self::assertNull($this->roundOf(PuzzleSolvingTimeFixture::TIME_09));
    }

    public function testSamePuzzleCannotBeInTwoRoundsOfOneCategory(): void
    {
        try {
            $this->messageBus->dispatch($this->attachPuzzle(CompetitionRoundFixture::ROUND_WJPC_FINAL, PuzzleFixture::PUZZLE_500_01));
            self::fail('Adding a puzzle to a second solo round must be rejected');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(PuzzleAlreadyInCompetitionRoundCategory::class, $previous);
            self::assertSame('Qualification Round', $previous->conflictingRoundName);
        }
    }

    public function testChangingCategoryIntoAConflictIsRejected(): void
    {
        // The final round becomes a duo round that shares the qualification puzzle...
        $this->database->executeStatement("UPDATE competition_round SET category = 'duo' WHERE id = :id", ['id' => CompetitionRoundFixture::ROUND_WJPC_FINAL]);
        $this->database->executeStatement(
            'INSERT INTO competition_round_puzzle (id, round_id, puzzle_id, hide_until_round_starts) VALUES (:id, :round, :puzzle, false)',
            ['id' => Uuid::uuid7()->toString(), 'round' => CompetitionRoundFixture::ROUND_WJPC_FINAL, 'puzzle' => PuzzleFixture::PUZZLE_500_01],
        );

        // ...so the qualification round cannot become duo as well
        $this->expectException(HandlerFailedException::class);

        $this->editRound(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, RoundCategory::Duo);
    }

    public function testChangingCategoryReconcilesTheRound(): void
    {
        $this->editRound(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, RoundCategory::Duo);

        // The fixture times are solo, so they no longer belong to the (now duo) round
        self::assertNull($this->roundOf(PuzzleSolvingTimeFixture::TIME_09));
    }

    public function testNewRoundGetsAUniqueReadableSlug(): void
    {
        $roundId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddCompetitionRound(
            roundId: $roundId,
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            name: 'Final Round',
            minutesLimit: 90,
            startsAt: new DateTimeImmutable('+40 days'),
            badgeBackgroundColor: null,
            badgeTextColor: null,
            resultsLink: 'https://example.com/results/final',
        ));

        /** @var array{slug: string, results_link: string}|false $row */
        $row = $this->database->fetchAssociative('SELECT slug, results_link FROM competition_round WHERE id = :id', ['id' => $roundId->toString()]);

        self::assertIsArray($row);
        // "final-round" is taken by the fixture round of the same competition
        self::assertSame('final-round-2', $row['slug']);
        self::assertSame('https://example.com/results/final', $row['results_link']);
    }

    public function testBackfillGivesSlugsOnlyToRoundsWithoutOne(): void
    {
        $this->database->executeStatement('UPDATE competition_round SET slug = NULL WHERE id = :id', ['id' => CompetitionRoundFixture::ROUND_CZECH_FINAL]);

        $this->messageBus->dispatch(new BackfillCompetitionRoundSlugs());

        self::assertSame('final-round', $this->database->fetchOne('SELECT slug FROM competition_round WHERE id = :id', ['id' => CompetitionRoundFixture::ROUND_CZECH_FINAL]));
        self::assertSame('qualification-round', $this->database->fetchOne('SELECT slug FROM competition_round WHERE id = :id', ['id' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION]));
    }

    /**
     * @param array<string> $groupPlayers
     */
    private function addTime(string $puzzleId, null|string $competitionId, array $groupPlayers = []): string
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            puzzleId: $puzzleId,
            competitionId: $competitionId,
            time: '00:45:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: null,
            firstAttempt: true,
            unboxed: false,
        ));

        return $timeId->toString();
    }

    private function attachPuzzle(string $roundId, string $puzzleId): AddPuzzleToCompetitionRound
    {
        return new AddPuzzleToCompetitionRound(
            roundPuzzleId: Uuid::uuid7(),
            roundId: $roundId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: $puzzleId,
            piecesCount: null,
            puzzlePhoto: null,
            puzzleEan: null,
            puzzleIdentificationNumber: null,
            hideUntilRoundStarts: false,
        );
    }

    private function editRound(string $roundId, RoundCategory $category): void
    {
        $this->messageBus->dispatch(new EditCompetitionRound(
            roundId: $roundId,
            name: 'Qualification Round',
            minutesLimit: 60,
            startsAt: new DateTimeImmutable('+30 days'),
            badgeBackgroundColor: null,
            badgeTextColor: null,
            category: $category,
        ));
    }

    private function roundOf(string $timeId): null|string
    {
        $round = $this->database->fetchOne('SELECT competition_round_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);

        return is_string($round) ? $round : null;
    }
}
