<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\OnlineResultRefused;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Message\ConfirmOnlineResult;
use SpeedPuzzling\Web\Results\ConfirmedOnlineResult;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OnlineReviewSeeding;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;
use SpeedPuzzling\Web\Value\OnlineConfirmedSource;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\RoundResultChangeStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The reviewer confirms a participant's online result (ConfirmOnlineResultHandler, docs/features/online-competitions/
 * README.md §5.9): the decision on the online entry and the official result in one transaction, through the official
 * results write path (receipts, `participant_round:<id>`, the reviewer as the one who entered it) - and a refusal
 * leaves nothing behind.
 */
final class ConfirmOnlineResultHandlerTest extends KernelTestCase
{
    use OnlineReviewSeeding;

    private const string COMPETITION = OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP;
    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string OLIVIA = OnlineRoundsFixture::PARTICIPANT_CONNECTED;
    private const string REVIEWER = PlayerFixture::PLAYER_ADMIN;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testATapBecomesTheOfficialTimeAndTheDecisionIsStoredWithIt(): void
    {
        $startMs = $this->startOnlineRound();
        $tapMs = $startMs + 1_000_345;
        $this->finishTap($this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED), $tapMs);

        $confirmed = $this->confirm($tapMs, OnlineConfirmedSource::Button);

        self::assertSame(RoundResultChangeStatus::Applied, $confirmed->status);
        self::assertSame(['seconds' => 1000], $confirmed->result->toWire());
        self::assertSame(['participant_round:' . OnlineRoundsFixture::ENTRY_CONNECTED], $confirmed->changedEntryRefs);
        self::assertTrue($confirmed->reviewRecorded);

        $official = $this->officialRow();
        self::assertSame(1000, $official['result_seconds']);
        self::assertNull($official['result_pieces_placed']);
        self::assertSame(self::REVIEWER, $official['result_entered_by_id']);
        self::assertNotNull($official['result_entered_at']);

        $review = $this->reviewRow();
        self::assertSame($tapMs, $review['confirmed_moment_ms']);
        self::assertSame('button', $review['confirmed_source']);
        self::assertSame('confirmed', $review['review_status']);
        self::assertSame(self::REVIEWER, $review['reviewed_by_id']);
        self::assertNotNull($review['reviewed_at']);

        // The official results write path's receipt - the change's own id, taken once
        self::assertSame(1, $this->receipts());
    }

    public function testAFrameOfTheBurstIsTheReviewersMoment(): void
    {
        $startMs = $this->startOnlineRound();
        $tapMs = $startMs + 600_000;
        $onlineEntry = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $tapId = $this->finishTap($onlineEntry, $tapMs);
        $this->evidence($onlineEntry, OnlineMediaKind::Burst, $tapMs, $tapId, self::burstManifest($tapMs));

        // The hands went up 1.2 s before the tap - frame captureMs = tap - 1200 + 0.4, rounded to the millisecond
        $confirmed = $this->confirm($tapMs - 1_200, OnlineConfirmedSource::Reviewer);

        self::assertSame(['seconds' => 598], $confirmed->result->toWire());
        self::assertSame($tapMs - 1_200, $this->reviewRow()['confirmed_moment_ms']);
        self::assertSame('reviewer', $this->reviewRow()['confirmed_source']);
        self::assertSame(598, $this->officialRow()['result_seconds']);
    }

    public function testAnUnfinishedPuzzleGetsItsPiecesPlaced(): void
    {
        $this->startOnlineRound();
        $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);

        $confirmed = $this->confirm(null, OnlineConfirmedSource::Reviewer, pieces: 2688);

        self::assertSame(RoundResultChangeStatus::Applied, $confirmed->status);
        self::assertSame(['piecesPlaced' => 2688], $confirmed->result->toWire());
        self::assertSame(2688, $this->officialRow()['result_pieces_placed']);
        self::assertNull($this->officialRow()['result_seconds']);

        $review = $this->reviewRow();
        self::assertNull($review['confirmed_moment_ms']);
        self::assertSame('reviewer', $review['confirmed_source']);
        self::assertSame('confirmed', $review['review_status']);
    }

    public function testConfirmingTheSameAgainChangesNothing(): void
    {
        $startMs = $this->startOnlineRound();
        $tapMs = $startMs + 1_500_000;
        $this->finishTap($this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED), $tapMs);
        $this->confirm($tapMs, OnlineConfirmedSource::Button);
        $enteredAt = $this->officialRow()['result_entered_at'];
        $reviewedAt = $this->reviewRow()['reviewed_at'];

        $again = $this->confirm($tapMs, OnlineConfirmedSource::Button, reviewer: PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertSame(RoundResultChangeStatus::Unchanged, $again->status);
        self::assertFalse($again->reviewRecorded);
        self::assertSame([], $again->changedEntryRefs, 'nothing official changed - nothing to tell the other pages');
        self::assertSame(1500, $this->officialRow()['result_seconds']);
        self::assertSame($enteredAt, $this->officialRow()['result_entered_at']);
        self::assertSame(self::REVIEWER, $this->officialRow()['result_entered_by_id']);
        self::assertSame($reviewedAt, $this->reviewRow()['reviewed_at']);
        self::assertSame(self::REVIEWER, $this->reviewRow()['reviewed_by_id']);
    }

    public function testAnotherDecisionReplacesThePreviousOne(): void
    {
        $startMs = $this->startOnlineRound();
        $tapMs = $startMs + 900_000;
        $this->finishTap($this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED), $tapMs);
        $this->confirm($tapMs, OnlineConfirmedSource::Button);

        // The reviewer looked again: unfinished after all
        $confirmed = $this->confirm(null, OnlineConfirmedSource::Reviewer, pieces: 2990);

        self::assertSame(RoundResultChangeStatus::Applied, $confirmed->status);
        self::assertSame(2990, $this->officialRow()['result_pieces_placed']);
        self::assertNull($this->officialRow()['result_seconds']);
        self::assertNull($this->reviewRow()['confirmed_moment_ms']);
    }

    /**
     * The official result's write path refuses the value (pieces placed must stay below the puzzle's 3000) - the whole
     * transaction rolls back: no result, no receipt, no decision on the online entry. A `conflict` outcome takes the
     * same path (409) - under the participants lock with `from` read right there it cannot come from a concurrent
     * write, only from the write path's own rules.
     */
    public function testARefusedResultRollsEverythingBack(): void
    {
        $this->startOnlineRound();
        $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);

        $this->assertRefused('invalid_pieces_placed', 422, fn () => $this->confirm(null, OnlineConfirmedSource::Reviewer, pieces: 3000));

        self::assertNull($this->officialRow()['result_pieces_placed']);
        self::assertNull($this->officialRow()['result_entered_at']);
        self::assertSame('pending', $this->reviewRow()['review_status']);
        self::assertNull($this->reviewRow()['reviewed_at']);
        self::assertSame(0, $this->receipts());
    }

    /**
     * "The review only chooses between recorded moments" (README §1 P2): a tap's own moment, or a frame of its burst
     */
    public function testAMomentThePhoneDidNotRecordIsRefused(): void
    {
        $startMs = $this->startOnlineRound();
        $tapMs = $startMs + 600_000;
        $onlineEntry = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $tapId = $this->finishTap($onlineEntry, $tapMs);
        $this->evidence($onlineEntry, OnlineMediaKind::Burst, $tapMs, $tapId, self::burstManifest($tapMs));

        // Between two frames
        $this->assertRefused(OnlineResultRefused::MOMENT_NOT_RECORDED, 422, fn () => $this->confirm($tapMs + 50, OnlineConfirmedSource::Reviewer));
        // A frame is the reviewer's moment, never "the button"
        $this->assertRefused(OnlineResultRefused::MOMENT_NOT_RECORDED, 422, fn () => $this->confirm($tapMs + 400, OnlineConfirmedSource::Button));
        // No candidate was ever detected
        $this->assertRefused(OnlineResultRefused::MOMENT_NOT_RECORDED, 422, fn () => $this->confirm($tapMs, OnlineConfirmedSource::Detection));

        self::assertNull($this->officialRow()['result_seconds']);
        self::assertSame(0, $this->receipts());
    }

    public function testAMomentOutsideTheRoundIsRefused(): void
    {
        $startMs = $this->startOnlineRound(600);
        $onlineEntry = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $early = $startMs - 5_000;
        $this->finishTap($onlineEntry, $early);
        $this->endOnlineRound(120);
        $late = $startMs + 590_000;
        $this->finishTap($onlineEntry, $late);

        $this->assertRefused(OnlineResultRefused::MOMENT_BEFORE_START, 422, fn () => $this->confirm($early, OnlineConfirmedSource::Button));
        // Ended 2 minutes ago, 8 minutes after the start - this tap came later
        $this->assertRefused(OnlineResultRefused::MOMENT_AFTER_END, 422, fn () => $this->confirm($late, OnlineConfirmedSource::Button));
    }

    public function testNothingToConfirmBeforeTheStart(): void
    {
        $onlineEntry = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $tapMs = (time() - 60) * 1000;
        $this->finishTap($onlineEntry, $tapMs);

        $this->assertRefused(OnlineResultRefused::ROUND_NOT_STARTED, 422, fn () => $this->confirm($tapMs, OnlineConfirmedSource::Button));
        // Nobody ran out of time either
        $this->assertRefused(OnlineResultRefused::ROUND_NOT_STARTED, 422, fn () => $this->confirm(null, OnlineConfirmedSource::Reviewer, pieces: 100));
    }

    public function testExactlyOneOfAMomentAndPieces(): void
    {
        $startMs = $this->startOnlineRound();
        $tapMs = $startMs + 600_000;
        $this->finishTap($this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED), $tapMs);

        $this->assertRefused(OnlineResultRefused::INVALID_CONFIRMATION, 422, fn () => $this->confirm($tapMs, OnlineConfirmedSource::Button, pieces: 100));
        $this->assertRefused(OnlineResultRefused::INVALID_CONFIRMATION, 422, fn () => $this->confirm(null, OnlineConfirmedSource::Reviewer));
        // A count is the reviewer's, never a device's moment
        $this->assertRefused(OnlineResultRefused::INVALID_CONFIRMATION, 422, fn () => $this->confirm(null, OnlineConfirmedSource::Button, pieces: 100));
    }

    public function testAParticipantWhosePhoneNeverConnectedHasNothingToConfirm(): void
    {
        $this->startOnlineRound();

        $this->assertRefused(
            OnlineResultRefused::NOT_CONNECTED,
            422,
            fn () => $this->confirm(null, OnlineConfirmedSource::Reviewer, pieces: 100, participantId: OnlineRoundsFixture::PARTICIPANT_ANONYMOUS),
        );
        // Not entered in the round at all
        $this->assertRefused(
            'entry_not_found',
            422,
            fn () => $this->confirm(null, OnlineConfirmedSource::Reviewer, pieces: 100, participantId: OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN),
        );
    }

    public function testARoundOfAnotherEventOrWithoutOnlineTimingIsNotFound(): void
    {
        $this->startOnlineRound();
        $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);

        $refused = false;

        try {
            $this->confirm(null, OnlineConfirmedSource::Reviewer, pieces: 100, competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP);
        } catch (OnlineRoundNotFound) {
            $refused = true;
        }

        self::assertTrue($refused, 'the round is not one of the authorised event');

        $refused = false;

        try {
            $this->dispatch(new ConfirmOnlineResult(OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::ROUND_GROUP_A, self::OLIVIA, null, OnlineConfirmedSource::Reviewer, 100, self::REVIEWER));
        } catch (OnlineRoundNotFound) {
            $refused = true;
        }

        self::assertTrue($refused, 'a round without online timing');
    }

    public function testItTakesTheEventsParticipantsLock(): void
    {
        $message = new ConfirmOnlineResult(strtoupper(self::COMPETITION), self::ROUND, self::OLIVIA, null, OnlineConfirmedSource::Reviewer, 10, self::REVIEWER);

        self::assertSame(CompetitionParticipantsLock::key(self::COMPETITION), $message->lockKey());
        self::assertSame('participant-import-' . self::COMPETITION, $message->lockKey());
    }

    private function confirm(
        null|int $momentMs,
        OnlineConfirmedSource $source,
        null|int $pieces = null,
        string $reviewer = self::REVIEWER,
        string $participantId = self::OLIVIA,
        string $competitionId = self::COMPETITION,
    ): ConfirmedOnlineResult {
        return $this->dispatch(new ConfirmOnlineResult($competitionId, self::ROUND, $participantId, $momentMs, $source, $pieces, $reviewer));
    }

    private function dispatch(ConfirmOnlineResult $message): ConfirmedOnlineResult
    {
        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch($message);
        $result = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(ConfirmedOnlineResult::class, $result);

        return $result;
    }

    private function assertRefused(string $reason, int $status, callable $confirm): void
    {
        try {
            $confirm();
        } catch (OnlineResultRefused $refused) {
            self::assertSame($reason, $refused->reason);
            self::assertSame($status, $refused->getStatusCode());

            return;
        }

        self::fail(sprintf('The confirmation should have been refused (%s).', $reason));
    }

    /**
     * @return array<string, mixed>
     */
    private function officialRow(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT result_seconds, result_pieces_placed, result_entered_at, result_entered_by_id FROM competition_participant_round WHERE id = :id',
            ['id' => OnlineRoundsFixture::ENTRY_CONNECTED],
        );
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewRow(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT confirmed_moment_ms, confirmed_source, review_status, reviewed_by_id, reviewed_at FROM online_entry WHERE entry_id = :entry',
            ['entry' => OnlineRoundsFixture::ENTRY_CONNECTED],
        );
        self::assertIsArray($row);

        return $row;
    }

    private function receipts(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM round_result_change_receipt WHERE round_id = :round', ['round' => self::ROUND]);

        return is_numeric($count) ? (int) $count : -1;
    }
}
