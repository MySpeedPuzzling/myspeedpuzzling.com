<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchAlreadyStarted;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchRefused;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\CancelRoundStart;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Message\ResetRoundStopwatch;
use SpeedPuzzling\Web\Message\ScheduleRoundStart;
use SpeedPuzzling\Web\Message\StartRoundStopwatch;
use SpeedPuzzling\Web\Message\StopRoundStopwatch;
use SpeedPuzzling\Web\Query\RoundPuzzlesVisibleNow;
use SpeedPuzzling\Web\Services\SecretRevealPreview;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ReadsRoundAutomaticReveal;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use SpeedPuzzling\Web\Value\RoundStopwatchRefusal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The reveal on the stopwatch start (RoundPuzzleReveal::Stopwatch - docs/features/competitions-management/toolkit/
 * A-round-formats.md §4, R-review-and-plan.md §2.1): a start reveals only with the tick and for the list confirmed, a
 * test run reveals nothing, a moved or called-off countdown never reveals at its old moment, and nothing once out is
 * hidden again. The puzzle's site-wide hide dates (SecretPuzzleHides) follow every step.
 */
final class StopwatchRevealTest extends KernelTestCase
{
    use ReadsRoundAutomaticReveal;

    private const string ROUND = CompetitionApiFixture::ROUND_FUTURE;
    private const string NEVER = CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED;

    private MessageBusInterface $messageBus;
    private Connection $database;
    private ClockInterface $clock;
    private string $roundPuzzleId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->clock = self::getContainer()->get(ClockInterface::class);
        $this->roundPuzzleId = $this->stopwatchSecretPuzzle();
    }

    public function testTheRowWaitsForTheStartLikeAManualReveal(): void
    {
        self::assertSame('stopwatch', $this->row()['reveal_mode']);
        self::assertNull($this->row()['reveal_at']);
        self::assertSame(self::NEVER, $this->row()['hide_until']);
        self::assertFalse($this->visibleAt(TestClock::now()->modify('+3 years')));
    }

    public function testATestRunWithoutTheTickRevealsNothing(): void
    {
        $this->dispatch(new StartRoundStopwatch(self::ROUND));

        self::assertSame('running', $this->stopwatchStatus());
        self::assertNull($this->row()['reveal_at']);
        self::assertSame(self::NEVER, $this->row()['hide_until']);
        self::assertFalse($this->visibleAt($this->clock->now()->modify('+1 hour')));
    }

    public function testAStartWithTheTickRevealsAtTheStart(): void
    {
        $now = $this->clock->now();

        $this->dispatch(new StartRoundStopwatch(self::ROUND, revealPuzzles: true, revealListHash: $this->shownHash()));

        self::assertSame('running', $this->stopwatchStatus());
        self::assertSame($now->format('Y-m-d H:i:s'), $this->row()['reveal_at']);
        self::assertSame($now->format('Y-m-d H:i:s'), $this->row()['hide_until']);
        // The mode stays: "Revealed at the stopwatch start"
        self::assertSame('stopwatch', $this->row()['reveal_mode']);
        self::assertTrue($this->visibleAt($now));
    }

    public function testAStaleListStartsNothing(): void
    {
        $this->expectRefused(RoundStopwatchRefusal::RevealChangedMeanwhile, fn () => $this->dispatch(new StartRoundStopwatch(self::ROUND, revealPuzzles: true, revealListHash: str_repeat('a', 64))));
        $this->expectRefused(RoundStopwatchRefusal::RevealChangedMeanwhile, fn () => $this->dispatch(new StartRoundStopwatch(self::ROUND, revealPuzzles: true)));

        self::assertNull($this->stopwatchStatus());
        self::assertNull($this->row()['reveal_at']);
    }

    public function testATickOnARoundWithNothingWaitingStartsNothing(): void
    {
        $round = CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION;

        $this->expectRefused(RoundStopwatchRefusal::RevealNotAllowed, fn () => $this->dispatch(new StartRoundStopwatch($round, revealPuzzles: true, revealListHash: str_repeat('a', 64))));

        self::assertNull($this->stopwatchStatus($round));
    }

    /**
     * R §2.1.1: schedule 10:00 ticked → move to 10:30 unticked → still hidden at 10:00 (and at 10:30)
     */
    public function testAMovedCountdownWithoutTheTickNeverRevealsAtTheOldMoment(): void
    {
        $first = $this->wholeSecond('+10 minutes');
        $later = $this->wholeSecond('+30 minutes');

        $this->schedule($first, reveal: true);
        self::assertSame($first->format('Y-m-d H:i:s'), $this->row()['reveal_at']);
        self::assertSame($first->format('Y-m-d H:i:s'), $this->row()['hide_until']);

        $this->schedule($later, reveal: false);

        self::assertSame($later->format('Y-m-d H:i:s'), $this->stopwatchStartedAt());
        self::assertNull($this->row()['reveal_at']);
        self::assertSame(self::NEVER, $this->row()['hide_until']);
        self::assertFalse($this->visibleAt($first));
        self::assertFalse($this->visibleAt($first->modify('+15 minutes')));
        self::assertFalse($this->visibleAt($later->modify('+1 hour')));
    }

    public function testAMovedCountdownWithTheTickRevealsAtTheNewMoment(): void
    {
        $first = $this->wholeSecond('+10 minutes');
        $later = $this->wholeSecond('+30 minutes');
        $earlier = $this->wholeSecond('+5 minutes');

        $this->schedule($first, reveal: true);
        $this->schedule($later, reveal: true);

        self::assertSame($later->format('Y-m-d H:i:s'), $this->row()['reveal_at']);
        self::assertSame($later->format('Y-m-d H:i:s'), $this->row()['hide_until']);
        self::assertFalse($this->visibleAt($first));

        $this->schedule($earlier, reveal: true);

        self::assertSame($earlier->format('Y-m-d H:i:s'), $this->row()['reveal_at']);
        self::assertSame($earlier->format('Y-m-d H:i:s'), $this->row()['hide_until']);
    }

    /**
     * R §2.1.2: a scheduled start with the tick keeps everything hidden until the countdown ends - one second before it
     * nothing, at it everything
     */
    public function testAScheduledStartWithTheTickRevealsAtTheEndOfTheCountdown(): void
    {
        $startsAt = $this->wholeSecond('+10 minutes');

        $this->schedule($startsAt, reveal: true);

        self::assertSame('scheduled', $this->stopwatchStatus());
        self::assertFalse($this->visibleAt($this->clock->now()));
        self::assertFalse($this->visibleAt($startsAt->modify('-1 second')));
        self::assertTrue($this->visibleAt($startsAt));
        self::assertSame($startsAt->getTimestamp() * 1000, self::getContainer()->get(RoundPuzzlesVisibleNow::class)->forRound(self::ROUND, $this->clock->now())->nextRevealAtMs);
    }

    public function testCancellingACountdownTakesItsRevealBack(): void
    {
        $this->schedule($this->wholeSecond('+10 minutes'), reveal: true);

        $this->dispatch(new CancelRoundStart(self::ROUND, PlayerFixture::PLAYER_ADMIN));

        self::assertNull($this->stopwatchStatus());
        self::assertNull($this->row()['reveal_at']);
        self::assertSame(self::NEVER, $this->row()['hide_until']);
    }

    public function testStoppingOrResettingACountdownTakesItsRevealBack(): void
    {
        $this->schedule($this->wholeSecond('+10 minutes'), reveal: true);
        $this->dispatch(new StopRoundStopwatch(self::ROUND));

        self::assertNull($this->stopwatchStatus());
        self::assertNull($this->row()['reveal_at']);
        self::assertSame(self::NEVER, $this->row()['hide_until']);

        $this->schedule($this->wholeSecond('+10 minutes'), reveal: true);
        $this->dispatch(new ResetRoundStopwatch(self::ROUND, CompetitionApiFixture::COMPETITION_API));

        self::assertNull($this->row()['reveal_at']);
        self::assertSame(self::NEVER, $this->row()['hide_until']);
    }

    /**
     * Test run unticked → reset → the real start ticked; a reset after the real start hides nothing again
     */
    public function testATestRunThenTheRealStartAndAResetAfterIt(): void
    {
        $this->dispatch(new StartRoundStopwatch(self::ROUND));
        $this->advance(120);
        $this->dispatch(new ResetRoundStopwatch(self::ROUND, CompetitionApiFixture::COMPETITION_API));
        self::assertNull($this->row()['reveal_at']);
        self::assertSame(self::NEVER, $this->row()['hide_until']);

        $this->advance(300);
        $realStart = $this->clock->now();
        $this->dispatch(new StartRoundStopwatch(self::ROUND, revealPuzzles: true, revealListHash: $this->shownHash()));
        self::assertSame($realStart->format('Y-m-d H:i:s'), $this->row()['reveal_at']);

        $this->advance(60);
        $this->dispatch(new ResetRoundStopwatch(self::ROUND, CompetitionApiFixture::COMPETITION_API));

        self::assertNull($this->stopwatchStatus());
        self::assertSame($realStart->format('Y-m-d H:i:s'), $this->row()['reveal_at']);
        self::assertSame($realStart->format('Y-m-d H:i:s'), $this->row()['hide_until']);
        self::assertTrue($this->visibleAt($this->clock->now()));
    }

    /**
     * Pause → resume with the tick reveals at the resume moment; a second "Start" on a running clock reveals nothing
     */
    public function testAResumeWithTheTickRevealsAtTheResume(): void
    {
        $this->dispatch(new StartRoundStopwatch(self::ROUND));
        $this->advance(180);
        $this->dispatch(new StartRoundStopwatch(self::ROUND, revealPuzzles: true, revealListHash: $this->shownHash()));
        self::assertNull($this->row()['reveal_at'], 'a start that changes nothing reveals nothing');

        $this->dispatch(new StopRoundStopwatch(self::ROUND));
        $this->advance(60);
        $resumedAt = $this->clock->now();
        $this->dispatch(new StartRoundStopwatch(self::ROUND, revealPuzzles: true, revealListHash: $this->shownHash()));

        self::assertSame('running', $this->stopwatchStatus());
        self::assertSame($resumedAt->format('Y-m-d H:i:s'), $this->row()['reveal_at']);
    }

    /**
     * R §2.1.3: the confirmation covers the stopwatch too - a page opened before the countdown was scheduled confirms
     * nothing, even with the same puzzles listed
     */
    public function testAPageFromBeforeTheCountdownConfirmsNothing(): void
    {
        $hashBefore = $this->shownHash();
        $this->schedule($this->wholeSecond('+10 minutes'), reveal: false);

        $this->expectRefused(RoundStopwatchRefusal::RevealChangedMeanwhile, fn () => $this->schedule($this->wholeSecond('+20 minutes'), reveal: true, hash: $hashBefore));

        self::assertNull($this->row()['reveal_at']);
    }

    public function testTheModeIsRefusedOnceTheStopwatchRan(): void
    {
        $this->dispatch(new ChangeRoundPuzzleReveal($this->roundPuzzleId, PuzzleHideMode::Entirely, RoundPuzzleReveal::Manual));
        $this->dispatch(new StartRoundStopwatch(self::ROUND));

        $this->expectException(RoundStopwatchAlreadyStarted::class);
        $this->dispatch(new ChangeRoundPuzzleReveal($this->roundPuzzleId, PuzzleHideMode::Entirely, RoundPuzzleReveal::Stopwatch));
    }

    private function stopwatchSecretPuzzle(): string
    {
        $roundPuzzleId = Uuid::uuid7();

        $this->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: self::ROUND,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: 'Stopwatch Secret ' . $roundPuzzleId->toString(),
            piecesCount: 500,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
            shownAutomaticRevealAt: self::automaticRevealOf(self::ROUND),
        ));
        $this->dispatch(new ChangeRoundPuzzleReveal($roundPuzzleId->toString(), PuzzleHideMode::Entirely, RoundPuzzleReveal::Stopwatch));

        return $roundPuzzleId->toString();
    }

    private function schedule(DateTimeImmutable $startsAt, bool $reveal, null|string $hash = null): void
    {
        $this->dispatch(new ScheduleRoundStart(
            self::ROUND,
            $startsAt,
            PlayerFixture::PLAYER_ADMIN,
            revealPuzzles: $reveal,
            revealListHash: $reveal ? ($hash ?? $this->shownHash()) : null,
        ));
    }

    /**
     * What the control page shows and sends with the tick
     */
    private function shownHash(): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $round = $entityManager->find(CompetitionRound::class, self::ROUND);
        self::assertNotNull($round);
        $preview = self::getContainer()->get(SecretRevealPreview::class);
        $revealed = $preview->byStopwatchStart($round);
        self::assertNotSame([], $revealed);
        $hash = SecretRevealPreview::stopwatchStartHash($revealed, $round);
        $entityManager->clear();

        return $hash;
    }

    private function visibleAt(DateTimeImmutable $moment): bool
    {
        $puzzleId = $this->database->fetchOne('SELECT puzzle_id FROM competition_round_puzzle WHERE id = :id', ['id' => $this->roundPuzzleId]);

        foreach (self::getContainer()->get(RoundPuzzlesVisibleNow::class)->forRound(self::ROUND, $moment)->puzzles as $puzzle) {
            if ($puzzle->puzzleId === $puzzleId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{reveal_mode: string, reveal_at: null|string, hide_until: null|string}
     */
    private function row(): array
    {
        /** @var array{reveal_mode: string, reveal_at: null|string, hide_until: null|string} $row */
        $row = $this->database->fetchAssociative(
            'SELECT crp.reveal_mode, crp.reveal_at, p.hide_until FROM competition_round_puzzle crp JOIN puzzle p ON p.id = crp.puzzle_id WHERE crp.id = :id',
            ['id' => $this->roundPuzzleId],
        );

        return $row;
    }

    private function stopwatchStatus(string $roundId = self::ROUND): null|string
    {
        $status = $this->database->fetchOne('SELECT stopwatch_status FROM competition_round WHERE id = :id', ['id' => $roundId]);

        return is_string($status) ? $status : null;
    }

    private function stopwatchStartedAt(): null|string
    {
        $startedAt = $this->database->fetchOne('SELECT stopwatch_started_at FROM competition_round WHERE id = :id', ['id' => self::ROUND]);

        return is_string($startedAt) ? $startedAt : null;
    }

    /**
     * The test container's clock is a MockClock (config/services_test.php) - it moves on at once
     */
    private function advance(int $seconds): void
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        $clock->sleep($seconds);
    }

    private function wholeSecond(string $modifier): DateTimeImmutable
    {
        $moment = $this->clock->now()->modify($modifier);

        return $moment->setTimestamp($moment->getTimestamp());
    }

    /**
     * @param \Closure(): void $action
     */
    private function expectRefused(RoundStopwatchRefusal $reason, \Closure $action): void
    {
        try {
            $action();
            self::fail(sprintf('Refused with %s expected', $reason->value));
        } catch (RoundStopwatchRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }

        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function dispatch(object $message): void
    {
        $this->messageBus->dispatch($message);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }
}
