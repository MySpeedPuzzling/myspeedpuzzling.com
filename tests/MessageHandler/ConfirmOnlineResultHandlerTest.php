<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\OnlineResultRefused;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Message\ConfirmOnlineResult;
use SpeedPuzzling\Web\Message\RecordRoundResults;
use SpeedPuzzling\Web\Results\ConfirmedOnlineResult;
use SpeedPuzzling\Web\Results\RecordedRoundResults;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OnlineReviewSeeding;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;
use SpeedPuzzling\Web\Value\OnlineConfirmedSource;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\RoundResultChange;
use SpeedPuzzling\Web\Value\RoundResultChangeStatus;
use SpeedPuzzling\Web\Value\RoundResultField;
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

        // Another reviewer, the result shown to them now
        $again = $this->confirm($tapMs, OnlineConfirmedSource::Button, reviewer: PlayerFixture::PLAYER_WITH_STRIPE, seen: RoundEntryResult::finished(1500));

        self::assertSame(RoundResultChangeStatus::Unchanged, $again->status);
        self::assertFalse($again->reviewRecorded);
        self::assertSame([], $again->changedEntryRefs, 'nothing official changed - nothing to tell the other pages');
        self::assertSame(1500, $this->officialRow()['result_seconds']);
        self::assertSame($enteredAt, $this->officialRow()['result_entered_at']);
        self::assertSame(self::REVIEWER, $this->officialRow()['result_entered_by_id']);
        self::assertSame($reviewedAt, $this->reviewRow()['reviewed_at']);
        self::assertSame(self::REVIEWER, $this->reviewRow()['reviewed_by_id']);

        // A request sent again (its answer got lost) still carries the empty result it was sent with - the entry holds
        // exactly what it asks for, so it is unchanged, no conflict
        $replay = $this->confirm($tapMs, OnlineConfirmedSource::Button);

        self::assertSame(RoundResultChangeStatus::Unchanged, $replay->status);
        self::assertFalse($replay->reviewRecorded);
        self::assertSame(1500, $this->officialRow()['result_seconds']);
    }

    public function testAnotherDecisionReplacesThePreviousOne(): void
    {
        $startMs = $this->startOnlineRound();
        $tapMs = $startMs + 900_000;
        $this->finishTap($this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED), $tapMs);
        $this->confirm($tapMs, OnlineConfirmedSource::Button);

        // The reviewer looked again (the review shows the 900 s confirmed before): unfinished after all
        $confirmed = $this->confirm(null, OnlineConfirmedSource::Reviewer, pieces: 2990, seen: RoundEntryResult::finished(900));

        self::assertSame(RoundResultChangeStatus::Applied, $confirmed->status);
        self::assertSame(2990, $this->officialRow()['result_pieces_placed']);
        self::assertNull($this->officialRow()['result_seconds']);
        self::assertNull($this->reviewRow()['confirmed_moment_ms']);
    }

    /**
     * The reviewer opened Olivia's evidence while she had no result; the results desk entered one meanwhile. Their
     * confirmation must not overwrite it: the official results' own three-way check answers `conflict` (409) with the
     * result the entry holds now, and nothing is written - no result, no receipt, no decision on the online entry.
     */
    public function testAResultEnteredMeanwhileIsAConflictNeverOverwritten(): void
    {
        $startMs = $this->startOnlineRound();
        $tapMs = $startMs + 1_000_345;
        $this->finishTap($this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED), $tapMs);
        $this->enterAtTheResultsDesk(RoundEntryResult::finished(1234));

        try {
            $this->confirm($tapMs, OnlineConfirmedSource::Button, seen: RoundEntryResult::none());
            self::fail('A result entered meanwhile must come back as a conflict.');
        } catch (OnlineResultRefused $refused) {
            self::assertSame(409, $refused->getStatusCode());
            self::assertSame('changed_meanwhile', $refused->reason);
            self::assertSame('official_results.reason.changed_meanwhile', $refused->translationKey());
            self::assertSame(['seconds' => 1234], $refused->current?->toWire());
        }

        self::assertSame(1234, $this->officialRow()['result_seconds']);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $this->officialRow()['result_entered_by_id']);
        self::assertSame('pending', $this->reviewRow()['review_status']);
        self::assertNull($this->reviewRow()['confirmed_moment_ms']);
        self::assertSame(1, $this->receipts(), 'the desk\'s change only');

        // Unfinished the same way
        $this->assertRefused('changed_meanwhile', 409, fn () => $this->confirm(null, OnlineConfirmedSource::Reviewer, pieces: 2000, seen: RoundEntryResult::finished(1199)));
    }

    /**
     * The review showed the desk's result - confirming replaces it: the reviewer decided knowing it
     */
    public function testTheResultTheReviewerSawIsReplaced(): void
    {
        $startMs = $this->startOnlineRound();
        $tapMs = $startMs + 1_000_345;
        $this->finishTap($this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED), $tapMs);
        $this->enterAtTheResultsDesk(RoundEntryResult::finished(1234));

        $confirmed = $this->confirm($tapMs, OnlineConfirmedSource::Button, seen: RoundEntryResult::finished(1234));

        self::assertSame(RoundResultChangeStatus::Applied, $confirmed->status);
        self::assertSame(1000, $this->officialRow()['result_seconds']);
        self::assertSame(self::REVIEWER, $this->officialRow()['result_entered_by_id']);
        self::assertSame('confirmed', $this->reviewRow()['review_status']);
        self::assertSame($tapMs, $this->reviewRow()['confirmed_moment_ms']);
    }

    /**
     * The official result's write path refuses the value (pieces placed must stay below the puzzle's 3000) - the whole
     * transaction rolls back: no result, no receipt, no decision on the online entry (a conflict the same way -
     * testAResultEnteredMeanwhileIsAConflictNeverOverwritten).
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
            $this->dispatch(new ConfirmOnlineResult(OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::ROUND_GROUP_A, self::OLIVIA, null, OnlineConfirmedSource::Reviewer, 100, self::REVIEWER, RoundEntryResult::none()));
        } catch (OnlineRoundNotFound) {
            $refused = true;
        }

        self::assertTrue($refused, 'a round without online timing');
    }

    public function testItTakesTheEventsParticipantsLock(): void
    {
        $message = new ConfirmOnlineResult(strtoupper(self::COMPETITION), self::ROUND, self::OLIVIA, null, OnlineConfirmedSource::Reviewer, 10, self::REVIEWER, RoundEntryResult::none());

        self::assertSame(CompetitionParticipantsLock::key(self::COMPETITION), $message->lockKey());
        self::assertSame('participant-import-' . self::COMPETITION, $message->lockKey());
    }

    /**
     * @param null|RoundEntryResult $seen the result the review showed - none by default (nothing official yet)
     */
    private function confirm(
        null|int $momentMs,
        OnlineConfirmedSource $source,
        null|int $pieces = null,
        string $reviewer = self::REVIEWER,
        string $participantId = self::OLIVIA,
        string $competitionId = self::COMPETITION,
        null|RoundEntryResult $seen = null,
    ): ConfirmedOnlineResult {
        return $this->dispatch(new ConfirmOnlineResult($competitionId, self::ROUND, $participantId, $momentMs, $source, $pieces, $reviewer, $seen ?? RoundEntryResult::none()));
    }

    /**
     * The organiser types a result for Olivia at the results desk - the official results write path, as its page sends it
     */
    private function enterAtTheResultsDesk(RoundEntryResult $result): void
    {
        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch(new RecordRoundResults(
            competitionId: self::COMPETITION,
            roundId: self::ROUND,
            actingPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            changes: [RoundResultChange::forEntry(
                clientChangeId: Uuid::uuid7()->toString(),
                entry: RoundEntryRef::participantRound(OnlineRoundsFixture::ENTRY_CONNECTED),
                field: RoundResultField::Result,
                from: RoundEntryResult::none(),
                to: $result,
            )],
        ));
        $recorded = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(RecordedRoundResults::class, $recorded);
        self::assertSame(RoundResultChangeStatus::Applied, $recorded->outcomes[0]->status);
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
