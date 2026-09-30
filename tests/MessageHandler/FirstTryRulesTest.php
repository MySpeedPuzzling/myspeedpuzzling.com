<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Events\GroupSolvingTimeEdited;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\FirstTryAlreadyTaken;
use SpeedPuzzling\Web\Exceptions\FirstTryConflictChanged;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\DismissFirstTryReview;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\ResolveFirstTryConflict;
use SpeedPuzzling\Web\Message\UnmarkFirstAttempt;
use SpeedPuzzling\Web\Query\GetFirstTryTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\FirstTryScenario;
use SpeedPuzzling\Web\Value\FirstTryResolution;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * docs/features/first-try-integrity.md - the handlers are the last word, whatever the form or the API sent.
 */
final class FirstTryRulesTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private FirstTryScenario $scenario;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->scenario = new FirstTryScenario(self::getContainer());
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testASecondFirstTryIsRefusedAndNothingIsSaved(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 10, firstTry: true);
        $timeId = Uuid::uuid7()->toString();

        $this->assertRefusedWith(FirstTryAlreadyTaken::class, fn() => $this->addFirstTry($timeId, PlayerFixture::PLAYER_WITH_STRIPE_USER_ID));

        self::assertFalse($this->scenario->exists($timeId));
    }

    public function testTheFirstTryMovesToTheNewResult(): void
    {
        $old = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 10, firstTry: true);
        $timeId = Uuid::uuid7()->toString();

        $this->addFirstTry($timeId, PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, resolution: FirstTryResolution::MoveHere);

        self::assertTrue($this->scenario->isFirstTry($timeId));
        self::assertFalse($this->scenario->isFirstTry($old));
    }

    public function testMovingOffAPairResultTellsTheOtherMember(): void
    {
        $pair = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin'], daysAgo: 10, firstTry: true);
        $this->asyncTransport()->reset();

        $this->addFirstTry(Uuid::uuid7()->toString(), PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, resolution: FirstTryResolution::MoveHere);

        self::assertFalse($this->scenario->isFirstTry($pair));

        $edits = array_values(array_filter(
            $this->asyncTransport()->getSent(),
            static fn(Envelope $envelope): bool => $envelope->getMessage() instanceof GroupSolvingTimeEdited,
        ));

        self::assertCount(1, $edits);
        $event = $edits[0]->getMessage();
        assert($event instanceof GroupSolvingTimeEdited);
        self::assertSame($pair, $event->puzzleSolvingTimeId->toString());
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $event->editedByPlayerId->toString());
    }

    public function testATeammatesFirstTryCannotBeMovedAway(): void
    {
        $theirs = $this->scenario->add(FirstTryScenario::ADMIN_USER_ID, daysAgo: 10, firstTry: true);
        $timeId = Uuid::uuid7()->toString();

        $this->assertRefusedWith(
            FirstTryAlreadyTaken::class,
            fn() => $this->addFirstTry($timeId, PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin'], FirstTryResolution::MoveHere),
        );

        self::assertFalse($this->scenario->exists($timeId));
        self::assertTrue($this->scenario->isFirstTry($theirs));
    }

    public function testAnEarlierSolveWithoutTheTagDoesNotBlock(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 10);
        $timeId = Uuid::uuid7()->toString();

        $this->addFirstTry($timeId, PlayerFixture::PLAYER_WITH_STRIPE_USER_ID);

        self::assertTrue($this->scenario->isFirstTry($timeId));
    }

    public function testWithoutTheTagNothingIsChecked(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 10, firstTry: true);

        self::assertTrue($this->scenario->exists($this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID)));
    }

    public function testAnEditTickingTheTagNewlyIsRefused(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 10, firstTry: true);
        $edited = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 5);

        $this->assertRefusedWith(FirstTryAlreadyTaken::class, fn() => $this->edit($edited, firstAttempt: true));

        self::assertFalse($this->scenario->isFirstTry($edited));
    }

    public function testAnEditCanMoveTheFirstTryToTheEditedResult(): void
    {
        $old = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 10, firstTry: true);
        $edited = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 5);

        $this->edit($edited, firstAttempt: true, resolution: FirstTryResolution::MoveHere);

        self::assertTrue($this->scenario->isFirstTry($edited));
        self::assertFalse($this->scenario->isFirstTry($old));
    }

    public function testAnOldDuplicateStillSavesAnEditThatChangesNeitherTagNorPeople(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 10, firstTry: true);
        $edited = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 5, firstTry: true);

        $this->edit($edited, firstAttempt: true, comment: 'Just a typo fixed');

        self::assertSame('Just a typo fixed', $this->database->fetchOne('SELECT comment FROM puzzle_solving_time WHERE id = :id', ['id' => $edited]));
        self::assertTrue($this->scenario->isFirstTry($edited));
    }

    public function testResolvingKeepsTheChosenFirstTryOnly(): void
    {
        $marked = $this->markedOfRegularOn500();
        self::assertGreaterThanOrEqual(2, count($marked));

        $this->messageBus->dispatch(new ResolveFirstTryConflict(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_01, PuzzleSolvingTimeFixture::TIME_09));

        self::assertSame([PuzzleSolvingTimeFixture::TIME_09], $this->markedOfRegularOn500());
    }

    public function testResolvingWithNoneTakesTheTagOffEverything(): void
    {
        $this->messageBus->dispatch(new ResolveFirstTryConflict(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_01, null));

        self::assertSame([], $this->markedOfRegularOn500());
    }

    public function testResolvingWithAResultOfSomebodyElseChangesNothing(): void
    {
        $before = $this->markedOfRegularOn500();

        // TIME_02 is PLAYER_PRIVATE's result of the same puzzle
        $this->assertRefusedWith(
            FirstTryConflictChanged::class,
            fn() => $this->messageBus->dispatch(new ResolveFirstTryConflict(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_01, PuzzleSolvingTimeFixture::TIME_02)),
        );

        self::assertSame($before, $this->markedOfRegularOn500());
    }

    public function testResolvingAnAlreadyResolvedConflictIsNoticed(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 10, firstTry: true);

        $this->assertRefusedWith(
            FirstTryConflictChanged::class,
            fn() => $this->messageBus->dispatch(new ResolveFirstTryConflict(PlayerFixture::PLAYER_WITH_STRIPE, FirstTryScenario::PUZZLE, null)),
        );
    }

    public function testUnmarkingSomebodyElsesResultIsRefused(): void
    {
        $theirs = $this->scenario->add(FirstTryScenario::ADMIN_USER_ID, daysAgo: 10, firstTry: true);

        $this->assertRefusedWith(
            CanNotModifyOtherPlayersTime::class,
            fn() => $this->messageBus->dispatch(new UnmarkFirstAttempt(PlayerFixture::PLAYER_WITH_STRIPE, $theirs)),
        );

        self::assertTrue($this->scenario->isFirstTry($theirs));
    }

    public function testAMemberUnmarksAPairResult(): void
    {
        $pair = $this->scenario->add(FirstTryScenario::ADMIN_USER_ID, ['#player4'], daysAgo: 10, firstTry: true);

        $this->messageBus->dispatch(new UnmarkFirstAttempt(PlayerFixture::PLAYER_WITH_STRIPE, $pair));

        self::assertFalse($this->scenario->isFirstTry($pair));
    }

    public function testDismissingTwiceKeepsOneRow(): void
    {
        $time = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 5, firstTry: true);

        $this->messageBus->dispatch(new DismissFirstTryReview(PlayerFixture::PLAYER_WITH_STRIPE, $time));
        $this->messageBus->dispatch(new DismissFirstTryReview(PlayerFixture::PLAYER_WITH_STRIPE, $time));

        self::assertEquals(1, $this->database->fetchOne('SELECT COUNT(*) FROM first_try_review_dismissal WHERE solving_time_id = :id', ['id' => $time]));
    }

    /**
     * @param list<string> $groupPlayers
     */
    private function addFirstTry(string $timeId, string $userId, array $groupPlayers = [], FirstTryResolution $resolution = FirstTryResolution::None): void
    {
        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: Uuid::fromString($timeId),
            userId: $userId,
            puzzleId: FirstTryScenario::PUZZLE,
            competitionId: null,
            time: '05:00:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: null,
            firstAttempt: true,
            unboxed: false,
            firstTryResolution: $resolution,
        ));
    }

    private function edit(string $timeId, bool $firstAttempt, FirstTryResolution $resolution = FirstTryResolution::None, null|string $comment = null): void
    {
        $this->messageBus->dispatch(new EditPuzzleSolvingTime(
            currentUserId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            puzzleSolvingTimeId: $timeId,
            competitionId: null,
            time: '05:00:00',
            comment: $comment,
            groupPlayers: [],
            finishedAt: $this->scenario->daysAgo(5),
            finishedPuzzlesPhoto: null,
            firstAttempt: $firstAttempt,
            unboxed: false,
            firstTryResolution: $resolution,
        ));
    }

    /**
     * @return list<string>
     */
    private function markedOfRegularOn500(): array
    {
        return self::getContainer()->get(GetFirstTryTimes::class)->markedTimeIdsOf(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_01);
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    private function assertRefusedWith(string $expected, callable $dispatch): void
    {
        try {
            $dispatch();
            self::fail("Expected {$expected}");
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf($expected, $exception->getPrevious());
        } catch (\Throwable $exception) {
            // HTTP exceptions leave the bus unwrapped (UnwrapHttpExceptionMiddleware)
            self::assertInstanceOf($expected, $exception);
        }
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        assert($transport instanceof InMemoryTransport);

        return $transport;
    }
}
