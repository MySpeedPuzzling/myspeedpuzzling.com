<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\DuplicateCaseChanged;
use SpeedPuzzling\Web\Message\ConfirmDuplicateIsReal;
use SpeedPuzzling\Web\Message\KeepDuplicateCopy;
use SpeedPuzzling\Web\Query\GetPlayerDuplicateCases;
use SpeedPuzzling\Web\Query\GetPlayerReviewCounts;
use SpeedPuzzling\Web\Results\DuplicateCopiesDeleted;
use SpeedPuzzling\Web\Results\DuplicateReviewCopy;
use SpeedPuzzling\Web\Results\DuplicateReviewSet;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\FirstTryScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * A result saved more than twice is one set of copies, decided at once (docs/features/duplicate-results.md,
 * "Review page"). Sarah, the admin and Michael each saved the same team result on PUZZLE_3000 - three copies,
 * three cases for each of them.
 */
final class DuplicateSetActionsTest extends KernelTestCase
{
    private const string SARAH = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string ADMIN = PlayerFixture::PLAYER_ADMIN;
    private const string MICHAEL = PlayerFixture::PLAYER_WITH_FAVORITES;

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

    public function testThreeCopiesByThreeTrackersAreOneSet(): void
    {
        [$bySarah, $byAdmin, $byMichael] = $this->teamResultSavedByAllThree();

        $set = $this->setOf(self::SARAH);

        self::assertSame([$bySarah, $byAdmin, $byMichael], $set->timeIds(), 'Oldest first');
        self::assertCount(3, $set->caseIds);
        self::assertSame(1, $set->countTrackedBy(self::SARAH));

        // The recap of the newest copy shows the whole set, also the copy linked to it only through another one
        $recap = self::getContainer()->get(GetPlayerDuplicateCases::class)->openOfTime(self::MICHAEL, $byMichael);
        self::assertCount(1, $recap);
        self::assertSame([$bySarah, $byAdmin, $byMichael], $recap[0]->timeIds());
    }

    public function testTheBannerCountsASetOnce(): void
    {
        $before = self::getContainer()->get(GetPlayerReviewCounts::class)->forPlayer(self::SARAH)->duplicates;

        $this->teamResultSavedByAllThree();

        // Three copies = three cases for Sarah, but one result saved several times - one card, one in the count
        self::assertSame(
            $before + 1,
            self::getContainer()->get(GetPlayerReviewCounts::class)->forPlayer(self::SARAH)->duplicates,
        );
    }

    public function testDeletingMyCopyClosesItsCasesForEverybodyAndTheRestStaysOpen(): void
    {
        [$bySarah, $byAdmin, $byMichael] = $this->teamResultSavedByAllThree();
        $set = $this->setOf(self::SARAH);

        // "Delete my copy" keeps the oldest of the others
        $outcome = $this->keep($set->caseId(), $set->keptInsteadOf($this->copy($set, $bySarah))->timeId, self::SARAH, $set->timeIds());

        self::assertSame(1, $outcome->deleted);
        self::assertFalse($this->scenario->exists($bySarah));
        self::assertTrue($this->scenario->exists($byAdmin));
        self::assertTrue($this->scenario->exists($byMichael));

        // Every case with Sarah's copy is closed - for all three of them
        self::assertSame(['copy_deleted' => 6], $this->statusesOfCasesWith($bySarah));
        // The admin's and Michael's copies are still two results: only their trackers decide about them
        self::assertSame(['open' => 3], $this->statuses([$byAdmin, $byMichael]));

        self::assertSame([$byAdmin, $byMichael], $this->setOf(self::SARAH)->timeIds());
    }

    public function testKeepingOneOfMyCopiesDeletesMyOtherCopiesOnly(): void
    {
        $first = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin'], daysAgo: 2, comment: 'first');
        $second = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin'], daysAgo: 2, comment: 'second');
        $byAdmin = $this->scenario->add(FirstTryScenario::ADMIN_USER_ID, ['#player4'], daysAgo: 2, comment: 'admin');
        $set = $this->setOf(self::SARAH);
        self::assertSame(2, $set->countTrackedBy(self::SARAH));

        $outcome = $this->keep($set->caseId(), $second, self::SARAH, $set->timeIds());

        self::assertSame(1, $outcome->deleted);
        self::assertFalse($this->scenario->exists($first));
        self::assertTrue($this->scenario->exists($second));
        self::assertTrue($this->scenario->exists($byAdmin), 'Somebody else\'s copy is never deleted');
        self::assertSame(['open' => 2], $this->statuses([$second, $byAdmin]));
        self::assertSame('second', $this->database->fetchOne('SELECT comment FROM puzzle_solving_time WHERE id = :id', ['id' => $second]));
    }

    public function testAllRealClosesEveryCaseOfTheSetForThatPersonOnly(): void
    {
        $copies = $this->teamResultSavedByAllThree();
        $set = $this->setOf(self::SARAH);

        $this->messageBus->dispatch(new ConfirmDuplicateIsReal($set->caseId(), self::SARAH, copyTimeIds: $set->timeIds()));

        self::assertSame(['both_real' => 3], $this->statuses($copies, self::SARAH));
        self::assertSame(['open' => 3], $this->statuses($copies, self::ADMIN));

        self::assertSame([], $this->openSetsOf(self::SARAH));
        self::assertCount(1, $this->openSetsOf(self::ADMIN), 'The others decide for themselves');
        self::assertCount(1, $this->openSetsOf(self::MICHAEL));
    }

    public function testASetThatChangedSinceThePageWasShownIsRefused(): void
    {
        [$bySarah, $byAdmin, $byMichael] = $this->teamResultSavedByAllThree();
        $set = $this->setOf(self::SARAH);

        // The page showed two copies only - Michael's arrived later
        $refusal = $this->refusalOf(fn () => $this->keep($set->caseId(), $byAdmin, self::SARAH, [$bySarah, $byAdmin]));
        self::assertInstanceOf(DuplicateCaseChanged::class, $refusal);

        $refusal = $this->refusalOf(fn () => $this->messageBus->dispatch(new ConfirmDuplicateIsReal($set->caseId(), self::SARAH, copyTimeIds: [$bySarah, $byAdmin])));
        self::assertInstanceOf(DuplicateCaseChanged::class, $refusal);

        self::assertTrue($this->scenario->exists($bySarah));
        self::assertTrue($this->scenario->exists($byMichael));
        self::assertSame(['open' => 9], $this->statuses([$bySarah, $byAdmin, $byMichael]));
    }

    public function testAPairResultTakesTheFirstTryOverWhenNobodyOfItHasAnother(): void
    {
        $kept = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin'], daysAgo: 2, comment: 'kept');
        $copy = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin'], daysAgo: 2, firstTry: true, comment: 'copy');
        $set = $this->setOf(self::SARAH);

        $outcome = $this->keep($set->caseId(), $kept, self::SARAH, $set->timeIds());

        self::assertFalse($outcome->firstTryLeftOff);
        self::assertTrue($this->scenario->isFirstTry($kept));
        self::assertFalse($this->scenario->exists($copy));
    }

    public function testAPairResultDoesNotTakeTheFirstTryOverWhenATeammateHasAnother(): void
    {
        // The admin's own first try of the puzzle, solo, weeks ago
        $adminsFirstTry = $this->scenario->add(FirstTryScenario::ADMIN_USER_ID, daysAgo: 20, firstTry: true, time: '02:00:00');
        $kept = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin'], daysAgo: 2, comment: 'kept');
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin'], daysAgo: 2, firstTry: true, comment: 'copy');
        $set = $this->setOf(self::SARAH);

        $outcome = $this->keep($set->caseId(), $kept, self::SARAH, $set->timeIds());

        self::assertTrue($outcome->firstTryLeftOff);
        self::assertFalse($this->scenario->isFirstTry($kept), 'It would be the admin\'s second first try');
        self::assertTrue($this->scenario->isFirstTry($adminsFirstTry));
        // Everything else is still taken over
        self::assertSame('kept', $this->database->fetchOne('SELECT comment FROM puzzle_solving_time WHERE id = :id', ['id' => $kept]));
    }

    public function testASoloResultAlwaysTakesTheFirstTryOver(): void
    {
        // Sarah's pair with the admin is marked as a first try too - an old conflict, not made worse by the move
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin'], daysAgo: 20, firstTry: true, time: '02:00:00');
        $kept = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 2, comment: 'kept');
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 2, firstTry: true, comment: 'copy');
        $set = $this->setOf(self::SARAH);

        $outcome = $this->keep($set->caseId(), $kept, self::SARAH, $set->timeIds());

        self::assertFalse($outcome->firstTryLeftOff);
        self::assertTrue($this->scenario->isFirstTry($kept));
    }

    /**
     * @return array{string, string, string} the copies by Sarah, the admin and Michael, in the order they were saved
     */
    private function teamResultSavedByAllThree(): array
    {
        return [
            $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin', '#player3'], daysAgo: 3, comment: 'Sarah'),
            $this->scenario->add(FirstTryScenario::ADMIN_USER_ID, ['#player4', '#player3'], daysAgo: 3, comment: 'Admin'),
            $this->scenario->add(PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID, ['#player4', '#admin'], daysAgo: 3, comment: 'Michael'),
        ];
    }

    /**
     * @param list<string> $copyTimeIds
     */
    private function keep(string $caseId, string $keepTimeId, string $playerId, array $copyTimeIds): DuplicateCopiesDeleted
    {
        $envelope = $this->messageBus->dispatch(new KeepDuplicateCopy($caseId, $keepTimeId, $playerId, copyTimeIds: $copyTimeIds));
        $outcome = $envelope->last(HandledStamp::class)?->getResult();
        assert($outcome instanceof DuplicateCopiesDeleted);

        return $outcome;
    }

    private function setOf(string $playerId): DuplicateReviewSet
    {
        $sets = $this->openSetsOf($playerId);
        self::assertCount(1, $sets);

        return $sets[0];
    }

    /**
     * @return list<DuplicateReviewSet> on the scenario's puzzle - other fixtures may have cases elsewhere
     */
    private function openSetsOf(string $playerId): array
    {
        return array_values(array_filter(
            self::getContainer()->get(GetPlayerDuplicateCases::class)->openOf($playerId),
            static fn (DuplicateReviewSet $set): bool => $set->puzzle->puzzleId === FirstTryScenario::PUZZLE,
        ));
    }

    private function copy(DuplicateReviewSet $set, string $timeId): DuplicateReviewCopy
    {
        foreach ($set->copies as $copy) {
            if ($copy->timeId === $timeId) {
                return $copy;
            }
        }

        self::fail('No such copy');
    }

    /**
     * @return array<string, int> how many cases with this result are in which status (anybody's)
     */
    private function statusesOfCasesWith(string $timeId): array
    {
        /** @var array<string, int> $statuses */
        $statuses = $this->database->fetchAllKeyValue(
            'SELECT status, COUNT(*) FROM result_duplicate_case WHERE :timeId IN (time_a_id, time_b_id) GROUP BY status',
            ['timeId' => $timeId],
        );

        return $statuses;
    }

    /**
     * @param list<string> $timeIds
     * @return array<string, int> how many cases between these results are in which status - anybody's, or one person's
     */
    private function statuses(array $timeIds, null|string $playerId = null): array
    {
        /** @var array<string, int> $statuses */
        $statuses = $this->database->fetchAllKeyValue(
            'SELECT status, COUNT(*) FROM result_duplicate_case WHERE time_a_id IN (:timeIds) AND time_b_id IN (:timeIds) AND (CAST(:playerId AS UUID) IS NULL OR player_id = :playerId) GROUP BY status',
            ['timeIds' => $timeIds, 'playerId' => $playerId],
            ['timeIds' => ArrayParameterType::STRING],
        );

        return $statuses;
    }

    private function refusalOf(\Closure $dispatch): null|\Throwable
    {
        try {
            $dispatch();
        } catch (HandlerFailedException $exception) {
            return $exception->getPrevious();
        }

        self::fail('The action was expected to be refused');
    }
}
