<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\SuspiciousTimePuzzleConfirmation;
use SpeedPuzzling\Web\Query\GetSuspiciousTimeQueue;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeNoticeRepository;
use SpeedPuzzling\Web\Results\SuspiciousTimePuzzleCard;
use SpeedPuzzling\Web\Results\SuspiciousTimePuzzleCardCase;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use SpeedPuzzling\Web\Tests\SuspiciousTimeQueueCases;
use SpeedPuzzling\Web\Value\SuspicionDirection;
use SpeedPuzzling\Web\Value\SuspiciousTimeQueueTab;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;
use SpeedPuzzling\Web\Value\SuspiciousTimeResponse;
use SpeedPuzzling\Web\Value\SuspiciousTimeTier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * docs/features/suspicious-time-review.md, "Moderator queue", "The piece count is wrong" and "A hard puzzle".
 */
final class GetSuspiciousTimeQueueTest extends KernelTestCase
{
    use SuspiciousTimeQueueCases;

    private GetSuspiciousTimeQueue $queue;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->queue = self::getContainer()->get(GetSuspiciousTimeQueue::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testEachTabListsItsCases(): void
    {
        self::assertSame([SuspiciousTimesFixture::CASE_PENDING_FAST], $this->queue->pending(SuspicionDirection::Fast, 1)->caseIds);
        self::assertSame([SuspiciousTimesFixture::CASE_PENDING_SLOW], $this->queue->pending(SuspicionDirection::Slow, 1)->caseIds);
        self::assertSame([SuspiciousTimesFixture::CASE_MARKED], $this->queue->decidedCaseIds(SuspiciousTimeQueueTab::Marked, 1));
        self::assertSame([], $this->queue->decidedCaseIds(SuspiciousTimeQueueTab::Replied, 1));
        self::assertSame([], $this->queue->decidedCaseIds(SuspiciousTimeQueueTab::Trusted, 1));

        $counts = $this->queue->counts();
        self::assertSame([1, 1, 0, 1, 0, 0], [$counts->fast, $counts->slow, $counts->replied, $counts->marked, $counts->trusted, $counts->decisions]);
        self::assertSame(2, $this->queue->countPending());
    }

    public function testAPlayersReplyOrEditBringsTheMarkedCaseToPlayerReplied(): void
    {
        $notice = self::getContainer()->get(SuspiciousTimeNoticeRepository::class)->get(SuspiciousTimesFixture::NOTICE_MARKED);
        $notice->respond(SuspiciousTimeResponse::SaysCorrect, 'It is right.', $this->now());
        $this->entityManager->flush();

        self::assertSame([SuspiciousTimesFixture::CASE_MARKED], $this->queue->decidedCaseIds(SuspiciousTimeQueueTab::Replied, 1));
        self::assertSame(1, $this->queue->counts()->replied);

        // Leaving it as it is answers nothing a moderator has to look at
        $this->database->executeStatement("UPDATE suspicious_time_notice SET response = 'left_as_is' WHERE id = :id", ['id' => SuspiciousTimesFixture::NOTICE_MARKED]);
        self::assertSame([], $this->queue->decidedCaseIds(SuspiciousTimeQueueTab::Replied, 1));

        // An edit of a marked time that still needs a person
        $this->database->executeStatement('UPDATE suspicious_time_case SET player_edited_at = NOW() WHERE id = :id', ['id' => SuspiciousTimesFixture::CASE_MARKED]);
        self::assertSame([SuspiciousTimesFixture::CASE_MARKED], $this->queue->decidedCaseIds(SuspiciousTimeQueueTab::Replied, 1));
    }

    public function testTrustedCasesAreVerifiedFine(): void
    {
        $this->database->executeStatement("UPDATE suspicious_time_case SET status = 'trusted', decided_at = NOW() WHERE id = :id", ['id' => SuspiciousTimesFixture::CASE_PENDING_SLOW]);

        self::assertSame([SuspiciousTimesFixture::CASE_PENDING_SLOW], $this->queue->decidedCaseIds(SuspiciousTimeQueueTab::Trusted, 1));
        self::assertSame([], $this->queue->pending(SuspicionDirection::Slow, 1)->caseIds);
        self::assertSame(1, $this->queue->counts()->trusted);
    }

    public function testStrongCasesComeBeforePossibleOnes(): void
    {
        // Eda's fast Lighthouse Cove time, a possible case with a far higher score
        $possible = $this->raiseCopyOf(SuspiciousTimesFixture::TIME_EDITION_FAST, [], SuspiciousTimeTier::Possible, score: 9.0);

        $page = $this->queue->pending(SuspicionDirection::Fast, 1);
        self::assertSame([SuspiciousTimesFixture::CASE_PENDING_FAST, $possible['caseId']], $page->caseIds);
        self::assertSame(2, $page->total);
    }

    public function testATimeLeadingItsPuzzleComesFirstWithinItsTier(): void
    {
        // Eda's time leads Lighthouse Cove (the statistics count it), Sam's Harbour Lights time is behind a faster one
        $leading = $this->raiseCopyOf(SuspiciousTimesFixture::TIME_EDITION_FAST, [], score: 2.5);
        $this->database->executeStatement(
            'INSERT INTO puzzle_statistics (puzzle_id, solved_times_count, solved_times_solo_count, fastest_time_solo, solved_times_duo_count, solved_times_team_count) VALUES (:harbour, 2, 2, 8000, 0, 0), (:lighthouse, 1, 1, 9600, 0, 0)
             ON CONFLICT (puzzle_id) DO UPDATE SET solved_times_solo_count = EXCLUDED.solved_times_solo_count, fastest_time_solo = EXCLUDED.fastest_time_solo',
            ['harbour' => SuspiciousTimesFixture::PUZZLE_HARBOUR, 'lighthouse' => SuspiciousTimesFixture::PUZZLE_LIGHTHOUSE_3000],
        );

        self::assertSame([$leading['caseId'], SuspiciousTimesFixture::CASE_PENDING_FAST], $this->queue->pending(SuspicionDirection::Fast, 1)->caseIds);
    }

    public function testSeveralPlayersRaisedOnOnePuzzleMakeAPuzzleCard(): void
    {
        $eda = $this->raiseCopyOf(SuspiciousTimesFixture::TIME_STEADY_FAST, ['player_id' => SuspiciousTimesFixture::PLAYER_EDITION]);

        $cards = $this->queue->puzzleCards(SuspicionDirection::Fast);
        self::assertCount(1, $cards);
        self::assertSame(SuspiciousTimesFixture::PUZZLE_HARBOUR, $cards[0]->puzzleId);
        self::assertSame(4000, $cards[0]->piecesCount);
        self::assertSame(2, $cards[0]->playersCount);
        self::assertTrue($cards[0]->isSeveralPlayers());
        self::assertEqualsCanonicalizing([SuspiciousTimesFixture::CASE_PENDING_FAST, $eda['caseId']], self::caseIdsOf($cards[0]));
        self::assertSame(SuspiciousTimeReasonCode::FasterThanUsual, $cards[0]->cases[0]->trigger()?->code);

        // ... and its cases are not in the list again
        self::assertSame([], $this->queue->pending(SuspicionDirection::Fast, 1)->caseIds);
        // The tab still counts them
        self::assertSame(2, $this->queue->counts()->fast);
        // A card is per direction
        self::assertSame([], $this->queue->puzzleCards(SuspicionDirection::Slow));
    }

    public function testRaisedTimesOfAPopularPuzzleMakeNoCard(): void
    {
        $eda = $this->raiseCopyOf(SuspiciousTimesFixture::TIME_STEADY_FAST, ['player_id' => SuspiciousTimesFixture::PLAYER_EDITION]);

        // 2 raised of 10 solo results is still a fifth - pair results are not comparable with solo times
        $this->setResultCounts(SuspiciousTimesFixture::PUZZLE_HARBOUR, solo: 10, duo: 40);
        self::assertCount(1, $this->queue->puzzleCards(SuspicionDirection::Fast));

        // Of 11 they are a chance on a popular puzzle - back in the list one by one
        $this->setResultCounts(SuspiciousTimesFixture::PUZZLE_HARBOUR, solo: 11, duo: 0);
        self::assertSame([], $this->queue->puzzleCards(SuspicionDirection::Fast));
        self::assertEqualsCanonicalizing([SuspiciousTimesFixture::CASE_PENDING_FAST, $eda['caseId']], $this->queue->pending(SuspicionDirection::Fast, 1)->caseIds);

        // ... unless the puzzle is far off its usual difficulty
        $this->setDifficulty(SuspiciousTimesFixture::PUZZLE_HARBOUR, 0.4);
        self::assertCount(1, $this->queue->puzzleCards(SuspicionDirection::Fast));
    }

    public function testRaisedPairResultsAreComparedWithThePuzzlesPairResults(): void
    {
        // Two pairs below the slow floor on one puzzle, saved by two different players
        $this->raiseCopyOf(SuspiciousTimesFixture::TIME_SLOW_PAIR, ['player_id' => SuspiciousTimesFixture::PLAYER_PARTNER], trigger: SuspiciousTimeReasonCode::BelowSlowFloor);
        $this->raiseCopyOf(SuspiciousTimesFixture::TIME_SLOW_PAIR, ['player_id' => SuspiciousTimesFixture::PLAYER_EDITION], trigger: SuspiciousTimeReasonCode::BelowSlowFloor);
        $puzzleId = SuspiciousTimesFixture::PUZZLE_MARATHON;

        $this->setResultCounts($puzzleId, solo: 1000, duo: 10);
        self::assertSame([$puzzleId], array_map(static fn (SuspiciousTimePuzzleCard $card): string => $card->puzzleId, $this->queue->puzzleCards(SuspicionDirection::Slow)));

        $this->setResultCounts($puzzleId, solo: 0, duo: 11);
        self::assertSame([], $this->queue->puzzleCards(SuspicionDirection::Slow));
    }

    public function testAHardPuzzleKeepsItsCardWithItsThresholdForItsPiecesCount(): void
    {
        $this->setDifficulty(SuspiciousTimesFixture::PUZZLE_ORCHARD, 2.5);
        $orchard = self::getContainer()->get(PuzzleRepository::class)->get(SuspiciousTimesFixture::PUZZLE_ORCHARD);
        $this->entityManager->persist(new SuspiciousTimePuzzleConfirmation($orchard, 520, Uuid::uuid7(), $this->now(), slowThreshold: 60.0));
        $this->entityManager->flush();

        // A case still raised under the threshold stays in the card - every case of a card is decided right there
        $cards = $this->queue->puzzleCards(SuspicionDirection::Slow);
        self::assertCount(1, $cards);
        self::assertSame(60.0, $cards[0]->slowThreshold);
        self::assertSame(60.0, $cards[0]->suggestedSlowThreshold());
        self::assertSame([SuspiciousTimesFixture::CASE_PENDING_SLOW], self::caseIdsOf($cards[0]));

        // The piece count changed - the threshold was about another one
        $this->database->executeStatement('UPDATE puzzle SET pieces_count = 500 WHERE id = :id', ['id' => SuspiciousTimesFixture::PUZZLE_ORCHARD]);

        $cards = $this->queue->puzzleCards(SuspicionDirection::Slow);
        self::assertNull($cards[0]->slowThreshold);
        // 49:08:00 against the predicted hour - a quarter above, rounded up
        self::assertSame(62.0, $cards[0]->suggestedSlowThreshold());
    }

    public function testAPuzzleFarOffItsUsualDifficultyMakesACardOnItsOwn(): void
    {
        $this->setDifficulty(SuspiciousTimesFixture::PUZZLE_HARBOUR, 0.4);
        $this->setDifficulty(SuspiciousTimesFixture::PUZZLE_ORCHARD, 2.5);

        $fastCards = $this->queue->puzzleCards(SuspicionDirection::Fast);
        self::assertCount(1, $fastCards);
        self::assertSame(1, $fastCards[0]->playersCount);
        self::assertFalse($fastCards[0]->isSeveralPlayers());
        self::assertSame([SuspiciousTimesFixture::CASE_PENDING_FAST], self::caseIdsOf($fastCards[0]));

        $slowCards = $this->queue->puzzleCards(SuspicionDirection::Slow);
        self::assertCount(1, $slowCards);
        self::assertSame(SuspiciousTimesFixture::PUZZLE_ORCHARD, $slowCards[0]->puzzleId);

        // An easy puzzle says nothing about slow times, a hard one nothing about fast ones
        $this->setDifficulty(SuspiciousTimesFixture::PUZZLE_HARBOUR, 2.5);
        $this->setDifficulty(SuspiciousTimesFixture::PUZZLE_ORCHARD, 0.4);
        self::assertSame([], $this->queue->puzzleCards(SuspicionDirection::Fast));
        self::assertSame([], $this->queue->puzzleCards(SuspicionDirection::Slow));
    }

    public function testTimesOnASecretPuzzleStayOutUntilTheReveal(): void
    {
        // The fixture puzzles are not approved - hidden until a moment in the future, they are a competition's secret
        $this->database->executeStatement(
            "UPDATE puzzle SET hide_until = :later WHERE id = :id",
            ['later' => $this->now()->modify('+1 day')->format('Y-m-d H:i:s'), 'id' => SuspiciousTimesFixture::PUZZLE_HARBOUR],
        );

        self::assertSame([], $this->queue->pending(SuspicionDirection::Fast, 1)->caseIds);
        self::assertSame(0, $this->queue->counts()->fast);
    }

    private function setResultCounts(string $puzzleId, int $solo, int $duo): void
    {
        $this->database->executeStatement(
            'INSERT INTO puzzle_statistics (puzzle_id, solved_times_count, solved_times_solo_count, solved_times_duo_count, solved_times_team_count) VALUES (:id, :total, :solo, :duo, 0)
             ON CONFLICT (puzzle_id) DO UPDATE SET solved_times_count = EXCLUDED.solved_times_count, solved_times_solo_count = EXCLUDED.solved_times_solo_count, solved_times_duo_count = EXCLUDED.solved_times_duo_count, solved_times_team_count = 0',
            ['id' => $puzzleId, 'total' => $solo + $duo, 'solo' => $solo, 'duo' => $duo],
        );
    }

    /**
     * @return list<string>
     */
    private static function caseIdsOf(SuspiciousTimePuzzleCard $card): array
    {
        return array_map(static fn (SuspiciousTimePuzzleCardCase $case): string => $case->caseId, $card->cases);
    }

    private function setDifficulty(string $puzzleId, float $score): void
    {
        $this->database->executeStatement(
            "INSERT INTO puzzle_difficulty (puzzle_id, difficulty_score, confidence, sample_size, computed_at) VALUES (:puzzleId, :score, 'high', 30, NOW())
             ON CONFLICT (puzzle_id) DO UPDATE SET difficulty_score = EXCLUDED.difficulty_score",
            ['puzzleId' => $puzzleId, 'score' => $score],
        );
    }

    private function now(): \DateTimeImmutable
    {
        return self::getContainer()->get(ClockInterface::class)->now();
    }
}
