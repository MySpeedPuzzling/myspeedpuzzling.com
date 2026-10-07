<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetSuspiciousTimeCaseDetail;
use SpeedPuzzling\Web\Repository\SuspiciousTimeNoticeRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use SpeedPuzzling\Web\Tests\SuspiciousTimeQueueCases;
use SpeedPuzzling\Web\Value\ExpectedTimeSource;
use SpeedPuzzling\Web\Value\SuspicionDirection;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;
use SpeedPuzzling\Web\Value\SuspiciousTimeResponse;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * docs/features/suspicious-time-review.md, "Moderator queue": what a card shows.
 */
final class GetSuspiciousTimeCaseDetailTest extends KernelTestCase
{
    use SuspiciousTimeQueueCases;

    private GetSuspiciousTimeCaseDetail $detail;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->detail = self::getContainer()->get(GetSuspiciousTimeCaseDetail::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheCardShowsTheTimeTheExpectationThePuzzleAndThePlayer(): void
    {
        $cards = $this->detail->cards([SuspiciousTimesFixture::CASE_PENDING_FAST]);

        self::assertCount(1, $cards);
        $card = $cards[0];
        self::assertSame(SuspiciousTimeCaseStatus::Pending, $card->status);
        self::assertSame(SuspicionDirection::Fast, $card->direction);
        self::assertSame(SuspiciousTimesFixture::STEADY_FAST_SECONDS, $card->seconds);
        self::assertSame(34200, $card->expectedSeconds);
        self::assertSame(ExpectedTimeSource::Baseline, $card->expectedSource);
        self::assertEqualsWithDelta(3.8, $card->ratio(), 0.001);
        self::assertTrue($card->isFasterThanExpected());
        self::assertSame(27000, $card->suggestedSeconds());
        self::assertSame([SuspiciousTimeReasonCode::FasterThanUsual, SuspiciousTimeReasonCode::HoursLeftOut], array_map(static fn ($reason) => $reason->code, $card->reasons));
        self::assertFalse($card->isEditedSinceScan());
        self::assertSame('Harbour Lights', $card->puzzleName);
        self::assertSame(4000, $card->piecesCount);
        self::assertSame('Sam Steady', $card->playerName);
        self::assertSame('steady1', $card->playerCode);
        self::assertFalse($card->playerPrivate);
        // 5 history results, the fast one and the typo
        self::assertSame(7, $card->soloResults);
        self::assertSame(0, $card->groupResults);
        self::assertSame(0, $card->groupShare());
        // Nobody else solved Harbour Lights
        self::assertSame(1, $card->leaderboardPlace);
        self::assertSame([], $card->sameDay);
        self::assertSame([], $card->notices);
    }

    public function testThePlayersNumbersAndOtherResultsOfThatDay(): void
    {
        $this->database->executeStatement(
            "INSERT INTO player_baseline (id, player_id, pieces_count, baseline_seconds, qualifying_solves_count, computed_at, baseline_type) VALUES (:id, :playerId, 4000, 34200, 5, NOW(), 'direct')
             ON CONFLICT (player_id, pieces_count) DO UPDATE SET baseline_seconds = 34200, qualifying_solves_count = 5, baseline_type = 'direct'",
            ['id' => Uuid::uuid7()->toString(), 'playerId' => SuspiciousTimesFixture::PLAYER_STEADY],
        );
        // Another result the same day, and someone faster on Harbour Lights
        $sameDay = $this->cloneSolvingTime(SuspiciousTimesFixture::TIME_STEADY_TYPO, ['days_ago' => 12, 'puzzle_id' => SuspiciousTimesFixture::PUZZLE_ORCHARD]);
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET finished_at = (SELECT finished_at FROM puzzle_solving_time WHERE id = :fast) WHERE id = :sameDay',
            ['fast' => SuspiciousTimesFixture::TIME_STEADY_FAST, 'sameDay' => $sameDay],
        );
        $this->cloneSolvingTime(SuspiciousTimesFixture::TIME_STEADY_FAST, ['player_id' => SuspiciousTimesFixture::PLAYER_EDITION, 'seconds_to_solve' => 8000]);

        $card = $this->detail->cards([SuspiciousTimesFixture::CASE_PENDING_FAST])[0];

        self::assertContains(['pieces' => 4000, 'seconds' => 34200, 'type' => 'direct', 'solves' => 5], $card->baselines);
        self::assertCount(1, $card->sameDay);
        self::assertSame($sameDay, $card->sameDay[0]->timeId);
        self::assertSame('Quiet Orchard', $card->sameDay[0]->puzzleName);
        self::assertSame(2, $card->leaderboardPlace);

        // A puzzle a competition keeps secret is never listed - like in every moderator queue
        $this->database->executeStatement("UPDATE puzzle SET hide_until = NOW() + INTERVAL '30 days' WHERE id = :id", ['id' => SuspiciousTimesFixture::PUZZLE_ORCHARD]);
        self::assertSame([], $this->detail->cards([SuspiciousTimesFixture::CASE_PENDING_FAST])[0]->sameDay);
    }

    public function testAnotherEditionKeptSecretSinceIsNeverNamed(): void
    {
        $this->database->executeStatement(
            'UPDATE suspicious_time_case SET reasons = :reasons, reasons_shown = :reasons WHERE id = :id',
            [
                'id' => SuspiciousTimesFixture::CASE_MARKED,
                'reasons' => '[{"code": "faster_than_usual", "params": {"expected": 3600, "entered": 1300, "ratio": 2.77, "pieces": 520, "source": "pace"}}, {"code": "other_edition", "params": {"puzzle_id": "' . SuspiciousTimesFixture::PUZZLE_LIGHTHOUSE_1600 . '", "name": "Lighthouse Cove", "pieces": 1600}}]',
            ],
        );
        self::assertCount(2, $this->detail->cards([SuspiciousTimesFixture::CASE_MARKED])[0]->reasons);

        // A competition hides the 1600-piece puzzle after the scan named it
        $this->database->executeStatement("UPDATE puzzle SET hide_until = NOW() + INTERVAL '30 days' WHERE id = :id", ['id' => SuspiciousTimesFixture::PUZZLE_LIGHTHOUSE_1600]);

        $card = $this->detail->cards([SuspiciousTimesFixture::CASE_MARKED])[0];
        self::assertSame([SuspiciousTimeReasonCode::FasterThanUsual], array_map(static fn ($reason) => $reason->code, $card->reasons));
        self::assertSame([SuspiciousTimeReasonCode::FasterThanUsual], array_map(static fn ($reason) => $reason->code, $card->reasonsShown));
    }

    public function testAPairsPeopleAndTheNoticesOfTheMark(): void
    {
        $pair = $this->raiseCopyOf(SuspiciousTimesFixture::TIME_SLOW_PAIR, [], trigger: SuspiciousTimeReasonCode::BelowSlowFloor, score: 15.0, expectedSeconds: 36000);
        $notice = self::getContainer()->get(SuspiciousTimeNoticeRepository::class)->get(SuspiciousTimesFixture::NOTICE_MARKED);
        $notice->respond(SuspiciousTimeResponse::SaysCorrect, 'Correct!', self::getContainer()->get(ClockInterface::class)->now());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        // In the order asked for
        $cards = $this->detail->cards([$pair['caseId'], SuspiciousTimesFixture::CASE_MARKED]);

        self::assertSame([$pair['caseId'], SuspiciousTimesFixture::CASE_MARKED], [$cards[0]->caseId, $cards[1]->caseId]);
        self::assertSame(SuspicionDirection::Slow, $cards[0]->direction);
        // A slow time's leaderboard place is not counted
        self::assertNull($cards[0]->leaderboardPlace);
        self::assertSame(['Pat Partner', 'Fay Flagged'], $cards[0]->groupMembers);
        self::assertEqualsWithDelta(15.0, $cards[0]->ratio(), 0.001);
        self::assertFalse($cards[0]->isFasterThanExpected());

        $marked = $cards[1];
        self::assertSame(SuspiciousTimeCaseStatus::Marked, $marked->status);
        self::assertTrue($marked->flagged);
        self::assertSame('Admin User', $marked->decidedByName);
        self::assertSame('Please check the hours.', $marked->moderatorNote);
        self::assertCount(2, $marked->reasonsShown);
        self::assertCount(1, $marked->currentNotices());
        self::assertCount(1, $marked->openReplies());
        self::assertSame('Correct!', $marked->openReplies()[0]->responseText);
    }

    public function testAPendingTimeEditedSinceTheScanIsShownAsSuch(): void
    {
        $this->database->executeStatement('UPDATE puzzle_solving_time SET seconds_to_solve = 27000 WHERE id = :id', ['id' => SuspiciousTimesFixture::TIME_STEADY_FAST]);

        $card = $this->detail->cards([SuspiciousTimesFixture::CASE_PENDING_FAST])[0];

        self::assertTrue($card->isEditedSinceScan());
        self::assertSame(27000, $card->seconds);
    }
}
