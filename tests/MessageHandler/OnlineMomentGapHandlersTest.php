<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\OnlineMomentVerdictRefused;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Message\MarkOnlineMomentGap;
use SpeedPuzzling\Web\Message\UnmarkOnlineMomentGap;
use SpeedPuzzling\Web\Results\OnlineMomentGapChange;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OnlineReviewSeeding;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The reviewer's G stored (MarkOnlineMomentGap / UnmarkOnlineMomentGap, docs/features/online-competitions/README.md §5.9,
 * stream RG): one verdict row per finish moment with the reviewer and the time, idempotent both ways, only on the
 * moments the review shows - the participant's own taps and raises in this round, never a raise of a `shadow` round -
 * and nothing else changes.
 */
final class OnlineMomentGapHandlersTest extends KernelTestCase
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

    public function testGStoresTheMomentAsAGapOnce(): void
    {
        $startMs = $this->startOnlineRound();
        $olivia = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $tapId = $this->finishTap($olivia, $startMs + 1_000_345);

        $marked = $this->mark($tapId);

        self::assertTrue($marked->changed);
        self::assertSame($olivia->id->toString(), $marked->onlineEntryId);
        $rows = $this->verdicts();
        self::assertCount(1, $rows);
        self::assertSame($tapId, $rows[0]['event_id']);
        self::assertSame($olivia->id->toString(), $rows[0]['online_entry_id']);
        self::assertSame('gap', $rows[0]['verdict']);
        self::assertSame(self::REVIEWER, $rows[0]['marked_by_id']);
        self::assertNotNull($rows[0]['marked_at']);

        // G again - by another reviewer too: the first mark stays, nothing is written
        $again = $this->mark(strtoupper($tapId), reviewer: PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertFalse($again->changed);
        self::assertSame($rows, $this->verdicts());

        // Nothing else changed: no decision on the entry, no official result
        $review = $this->connection->fetchAssociative('SELECT review_status, confirmed_moment_ms FROM online_entry WHERE id = :id', ['id' => $olivia->id->toString()]);
        self::assertSame(['review_status' => 'pending', 'confirmed_moment_ms' => null], $review);
        self::assertNull($this->connection->fetchOne('SELECT result_seconds FROM competition_participant_round WHERE id = :id', ['id' => OnlineRoundsFixture::ENTRY_CONNECTED]));
    }

    public function testNotAGapTakesTheMarkBack(): void
    {
        $startMs = $this->startOnlineRound();
        $tapId = $this->finishTap($this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED), $startMs + 600_000);
        $this->mark($tapId);

        $unmarked = $this->unmark($tapId);

        self::assertTrue($unmarked->changed);
        self::assertSame([], $this->verdicts());
        self::assertFalse($this->unmark($tapId)->changed, 'a moment without a mark stays as it is');
        self::assertTrue($this->mark($tapId)->changed, 'and can be marked again');
    }

    public function testARaiseOfTheHandsIsMarkedWhereTheReviewerSeesIt(): void
    {
        $this->detectionMode(OnlineDetectionMode::On);
        $startMs = $this->startOnlineRound();
        $olivia = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $raiseId = $this->finishCandidate($olivia, $startMs + 500_000);

        self::assertTrue($this->mark($raiseId)->changed);
        self::assertSame([$raiseId], array_column($this->verdicts(), 'event_id'));
    }

    /**
     * `shadow` (stream P2F): the reviewer never sees a raise - one asked for is unknown, never told apart; the taps are
     * marked as in `off`
     */
    public function testInAShadowRoundOnlyTheTapsAreMarked(): void
    {
        $this->detectionMode(OnlineDetectionMode::Shadow);
        $startMs = $this->startOnlineRound();
        $olivia = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $raiseId = $this->finishCandidate($olivia, $startMs + 500_000, ['mode' => 'shadow']);
        $tapId = $this->finishTap($olivia, $startMs + 502_000);

        $this->assertRefused(fn () => $this->mark($raiseId));
        $this->assertRefused(fn () => $this->unmark($raiseId));
        self::assertSame([], $this->verdicts());

        self::assertTrue($this->mark($tapId)->changed);
        self::assertSame([$tapId], array_column($this->verdicts(), 'event_id'));
    }

    public function testOnlyTheParticipantsOwnFinishMomentsInTheRound(): void
    {
        $startMs = $this->startOnlineRound();
        $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $arthurTap = $this->finishTap($this->onlineEntryOf(OnlineRoundsFixture::ENTRY_ANONYMOUS), $startMs + 700_000);

        // Not an event at all, not even an id
        $this->assertRefused(fn () => $this->mark(Uuid::uuid7()->toString()));
        $this->assertRefused(fn () => $this->mark('not-a-moment'));
        // Another participant's tap
        $this->assertRefused(fn () => $this->mark($arthurTap));
        // An event of hers that is no finish moment ("I'm ready")
        $this->assertRefused(fn () => $this->mark(OnlineRoundsFixture::EVENT_READY_CONNECTED));
        self::assertSame([], $this->verdicts());

        // Arthur's tap is Arthur's to mark
        self::assertTrue($this->mark($arthurTap, participantId: OnlineRoundsFixture::PARTICIPANT_ANONYMOUS)->changed);
    }

    public function testARoundOfAnotherEventOrWithoutOnlineTimingIsNotFound(): void
    {
        $startMs = $this->startOnlineRound();
        $tapId = $this->finishTap($this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED), $startMs + 600_000);

        $messages = [
            'the round is not one of the authorised event' => new MarkOnlineMomentGap(OfficialResultsFixture::COMPETITION_RESULTS_CUP, self::ROUND, self::OLIVIA, $tapId, self::REVIEWER),
            'a round without online timing' => new UnmarkOnlineMomentGap(OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::ROUND_GROUP_A, self::OLIVIA, $tapId, self::REVIEWER),
        ];

        foreach ($messages as $case => $message) {
            $refused = false;

            try {
                $this->dispatch($message);
            } catch (OnlineRoundNotFound) {
                $refused = true;
            }

            self::assertTrue($refused, $case);
        }

        self::assertSame([], $this->verdicts());
    }

    public function testMarkAndUndoTakeTurnsUnderTheMomentsOwnLock(): void
    {
        $eventId = '018D0050-0000-7000-8000-0000000009AB';
        $key = (new MarkOnlineMomentGap(self::COMPETITION, self::ROUND, self::OLIVIA, $eventId, self::REVIEWER))->lockKey();

        self::assertSame('online-moment-verdict-018d0050-0000-7000-8000-0000000009ab', $key);
        self::assertSame($key, (new UnmarkOnlineMomentGap(self::COMPETITION, self::ROUND, self::OLIVIA, strtolower($eventId), PlayerFixture::PLAYER_WITH_STRIPE))->lockKey());
        self::assertNotSame($key, (new MarkOnlineMomentGap(self::COMPETITION, self::ROUND, self::OLIVIA, Uuid::uuid7()->toString(), self::REVIEWER))->lockKey());
    }

    private function mark(string $eventId, string $participantId = self::OLIVIA, string $reviewer = self::REVIEWER): OnlineMomentGapChange
    {
        return $this->dispatch(new MarkOnlineMomentGap(self::COMPETITION, self::ROUND, $participantId, $eventId, $reviewer));
    }

    private function unmark(string $eventId, string $participantId = self::OLIVIA): OnlineMomentGapChange
    {
        return $this->dispatch(new UnmarkOnlineMomentGap(self::COMPETITION, self::ROUND, $participantId, $eventId, self::REVIEWER));
    }

    private function dispatch(MarkOnlineMomentGap|UnmarkOnlineMomentGap $message): OnlineMomentGapChange
    {
        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch($message);
        $change = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(OnlineMomentGapChange::class, $change);

        return $change;
    }

    private function assertRefused(callable $write): void
    {
        try {
            $write();
        } catch (OnlineMomentVerdictRefused $refused) {
            self::assertSame(OnlineMomentVerdictRefused::MOMENT_NOT_FOUND, $refused->reason);
            self::assertSame(422, $refused->getStatusCode());

            return;
        }

        self::fail('The gap mark should have been refused.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function verdicts(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT event_id::text AS event_id, online_entry_id::text AS online_entry_id, verdict, marked_by_id::text AS marked_by_id, marked_at FROM online_review_moment_verdict ORDER BY marked_at, id');

        return $rows;
    }
}
