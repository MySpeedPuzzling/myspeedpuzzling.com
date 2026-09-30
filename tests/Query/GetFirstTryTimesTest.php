<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Message\DismissFirstTryReview;
use SpeedPuzzling\Web\Query\GetFirstTryTimes;
use SpeedPuzzling\Web\Results\FirstTryConflict;
use SpeedPuzzling\Web\Results\FirstTryTime;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\FirstTryScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class GetFirstTryTimesTest extends KernelTestCase
{
    private GetFirstTryTimes $query;
    private FirstTryScenario $scenario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetFirstTryTimes::class);
        $this->scenario = new FirstTryScenario(self::getContainer());
    }

    public function testFixtureDuplicatesAreFound(): void
    {
        // PLAYER_REGULAR marked PUZZLE_500_01 (TIME_01 + the competition TIME_09 + one more), PUZZLE_500_03
        // and PUZZLE_1000_02 more than once
        $conflicts = $this->query->conflictsOf(PlayerFixture::PLAYER_REGULAR);
        $puzzles = array_map(static fn(FirstTryConflict $conflict): string => $conflict->puzzle->puzzleId, $conflicts);

        self::assertContains(PuzzleFixture::PUZZLE_500_01, $puzzles);
        self::assertSame(count($conflicts), $this->query->conflictCountOf(PlayerFixture::PLAYER_REGULAR));

        $index = array_search(PuzzleFixture::PUZZLE_500_01, $puzzles, true);
        assert(is_int($index));
        $puzzle500 = $conflicts[$index];
        $timeIds = array_map(static fn(FirstTryTime $time): string => $time->timeId, $puzzle500->times);

        self::assertContains(PuzzleSolvingTimeFixture::TIME_01, $timeIds);
        self::assertContains(PuzzleSolvingTimeFixture::TIME_09, $timeIds);

        foreach ($puzzle500->times as $time) {
            self::assertTrue($time->firstAttempt);
        }
    }

    public function testConflictsOfAPairCountForEveryMember(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin'], daysAgo: 12, firstTry: true);
        $this->scenario->add(FirstTryScenario::ADMIN_USER_ID, daysAgo: 5, firstTry: true);

        $before = $this->countFor(PlayerFixture::PLAYER_WITH_STRIPE);
        $conflicts = $this->conflictsOnScenarioPuzzle(PlayerFixture::PLAYER_ADMIN);

        self::assertNotNull($conflicts);
        self::assertCount(2, $conflicts->times);
        self::assertNull($this->conflictsOnScenarioPuzzle(PlayerFixture::PLAYER_WITH_STRIPE), 'Only one of the two is Sarah\'s');
        self::assertSame($before, $this->countFor(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testTheOldestMarkedResultIsSuggestedAndAnEarlierUnmarkedSolveNoted(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 20);
        $older = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 10, firstTry: true);
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin'], daysAgo: 5, firstTry: true);

        $conflict = $this->conflictsOnScenarioPuzzle(PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertNotNull($conflict);
        self::assertSame($older, $conflict->suggestedTimeId());
        self::assertNotNull($conflict->earlierUnmarkedSolvedAt);
        self::assertSame($this->scenario->daysAgo(20)->format('Y-m-d'), $conflict->earlierUnmarkedSolvedAt->format('Y-m-d'));

        $pair = $conflict->times[1];
        self::assertTrue($pair->isPair());
        self::assertSame('Admin User', $pair->peopleExcept(PlayerFixture::PLAYER_WITH_STRIPE)[0]->label());
    }

    public function testAnUnmarkedSolveAfterTheFirstMarkedOneIsNotNoted(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 10, firstTry: true);
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 8);
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 5, firstTry: true);

        $conflict = $this->conflictsOnScenarioPuzzle(PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertNotNull($conflict);
        self::assertNull($conflict->earlierUnmarkedSolvedAt);
    }

    public function testMarkedTimeIdsOfAPuzzle(): void
    {
        $solo = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 10, firstTry: true);
        $pair = $this->scenario->add(FirstTryScenario::ADMIN_USER_ID, ['#player4'], daysAgo: 5, firstTry: true);
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 3);

        self::assertSame([$solo, $pair], $this->query->markedTimeIdsOf(PlayerFixture::PLAYER_WITH_STRIPE, FirstTryScenario::PUZZLE));
    }

    public function testAFirstTryAfterAnEarlierSolveIsListedUntilDismissed(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 10);
        $late = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 5, firstTry: true);

        $found = $this->lateOnScenarioPuzzle(PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertSame([$late], $found);

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new DismissFirstTryReview(PlayerFixture::PLAYER_WITH_STRIPE, $late));

        self::assertSame([], $this->lateOnScenarioPuzzle(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testAPuzzleWithAConflictIsNotListedAsLate(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 10);
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 5, firstTry: true);
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 3, firstTry: true);

        self::assertSame([], $this->lateOnScenarioPuzzle(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testATeammatesEarlierSolveDoesNotMakeItLate(): void
    {
        // Only the player's own history is reviewed on their page
        $this->scenario->add(FirstTryScenario::ADMIN_USER_ID, daysAgo: 10);
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin'], daysAgo: 5, firstTry: true);

        self::assertSame([], $this->lateOnScenarioPuzzle(PlayerFixture::PLAYER_WITH_STRIPE));
        self::assertCount(1, $this->lateOnScenarioPuzzle(PlayerFixture::PLAYER_ADMIN));
    }

    private function countFor(string $playerId): int
    {
        return $this->query->conflictCountOf($playerId);
    }

    private function conflictsOnScenarioPuzzle(string $playerId): null|FirstTryConflict
    {
        foreach ($this->query->conflictsOf($playerId) as $conflict) {
            if ($conflict->puzzle->puzzleId === FirstTryScenario::PUZZLE) {
                return $conflict;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function lateOnScenarioPuzzle(string $playerId): array
    {
        $ids = [];

        foreach ($this->query->lateFirstTriesOf($playerId) as $late) {
            if ($late->puzzle->puzzleId === FirstTryScenario::PUZZLE) {
                $ids[] = $late->time->timeId;
            }
        }

        return $ids;
    }
}
