<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\FirstTry;

use SpeedPuzzling\Web\Services\FirstTry\FirstTryAssessor;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\FirstTryScenario;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\FirstTryAssessment;
use SpeedPuzzling\Web\Value\FirstTryEntry;
use SpeedPuzzling\Web\Value\FirstTryNoticeLine;
use SpeedPuzzling\Web\Value\FirstTryResolution;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FirstTryAssessorTest extends KernelTestCase
{
    private FirstTryAssessor $assessor;
    private FirstTryScenario $scenario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->assessor = self::getContainer()->get(FirstTryAssessor::class);
        $this->scenario = new FirstTryScenario(self::getContainer());
    }

    public function testNothingToSayWithoutHistory(): void
    {
        $assessment = $this->assess(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE]);

        self::assertTrue($assessment->isEmpty());
        self::assertFalse($assessment->blocks(FirstTryResolution::None));
    }

    public function testOwnSoloFirstTryBlocksAndCanBeMovedHere(): void
    {
        $old = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 10, firstTry: true);

        $assessment = $this->assess(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE]);

        self::assertTrue($assessment->blocks(FirstTryResolution::None));
        self::assertTrue($assessment->viewerCanMove);
        self::assertFalse($assessment->blocks(FirstTryResolution::MoveHere));
        self::assertSame([$old], $assessment->timeIdsToUnmark(FirstTryResolution::MoveHere));
        self::assertSame([], $assessment->timeIdsToUnmark(FirstTryResolution::None));

        self::assertCount(1, $assessment->holdLines);
        self::assertSame(FirstTryNoticeLine::OWN, $assessment->holdLines[0]->kind);
        self::assertSame([], $assessment->holdLines[0]->with);
    }

    /**
     * H12 scenario 14 (docs/features/events-page/high-frequency-series.md): first tries read no event column - a
     * series-level time (a series pick no edition holds) is a first try like any other result
     */
    public function testSeriesLevelFirstTryBlocksLikeAnyOther(): void
    {
        $series = new SeriesEditionScenario(self::getContainer());
        $old = $series->addTime(
            PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            FirstTryScenario::PUZZLE,
            $this->scenario->daysAgo(10)->format('Y-m-d'),
            seriesId: $series->series(),
        );
        self::assertNull($series->link($old)['competition_id']);
        $this->scenario->markFirstTry($old);

        $assessment = $this->assess(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE]);

        self::assertTrue($assessment->blocks(FirstTryResolution::None));
        self::assertSame([$old], $assessment->timeIdsToUnmark(FirstTryResolution::MoveHere));
        self::assertSame(FirstTryNoticeLine::OWN, $assessment->holdLines[0]->kind);
    }

    public function testOwnPairFirstTryBlocksASoloAttempt(): void
    {
        $pair = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin'], daysAgo: 10, firstTry: true);

        $assessment = $this->assess(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE]);

        self::assertTrue($assessment->blocks(FirstTryResolution::None));
        self::assertTrue($assessment->viewerCanMove, 'The viewer took part in the pair result, so they may move the tag');
        self::assertSame([$pair], $assessment->timeIdsToUnmark(FirstTryResolution::MoveHere));
        self::assertSame(['Admin User'], $assessment->holdLines[0]->with);
    }

    public function testTeammatesOwnFirstTryCannotBeMovedFromHere(): void
    {
        $this->scenario->add(FirstTryScenario::ADMIN_USER_ID, daysAgo: 10, firstTry: true);

        $assessment = $this->assess(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN]);

        self::assertTrue($assessment->blocks(FirstTryResolution::None));
        self::assertFalse($assessment->viewerCanMove);
        self::assertTrue($assessment->blocks(FirstTryResolution::MoveHere), 'Another player\'s own result is never changed from here');
        self::assertSame([], $assessment->timeIdsToUnmark(FirstTryResolution::MoveHere));

        self::assertCount(1, $assessment->holdLines);
        self::assertSame(FirstTryNoticeLine::TEAMMATE, $assessment->holdLines[0]->kind);
        self::assertSame('Admin User', $assessment->holdLines[0]->person?->name);
    }

    public function testAMixOfOwnAndTeammatesFirstTriesCannotBeMoved(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 12, firstTry: true);
        $this->scenario->add(FirstTryScenario::ADMIN_USER_ID, daysAgo: 10, firstTry: true);

        $assessment = $this->assess(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN]);

        self::assertCount(2, $assessment->holds);
        self::assertFalse($assessment->viewerCanMove);
        self::assertTrue($assessment->blocks(FirstTryResolution::MoveHere));
    }

    public function testGuestsAreLeftOut(): void
    {
        // The same name in two groups is not the same person as far as we can tell
        $this->scenario->add(FirstTryScenario::ADMIN_USER_ID, ['Anna'], daysAgo: 10, firstTry: true);

        $assessment = $this->assess(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE]);

        self::assertTrue($assessment->isEmpty());
    }

    public function testAnEarlierSolveWithoutTheTagWarnsButDoesNotBlock(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 5);
        $this->scenario->add(FirstTryScenario::ADMIN_USER_ID, daysAgo: 3);

        $assessment = $this->assess(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN]);

        self::assertFalse($assessment->blocks(FirstTryResolution::None));
        self::assertFalse($assessment->hasHolds());
        self::assertCount(2, $assessment->earlierLines);
        self::assertSame(FirstTryNoticeLine::OWN, $assessment->earlierLines[0]->kind);
        self::assertSame(FirstTryNoticeLine::TEAMMATE, $assessment->earlierLines[1]->kind);
        self::assertSame('Admin User', $assessment->earlierLines[1]->person?->name);
    }

    public function testASolveFromTheSameDayIsNotEarlier(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 0);

        $assessment = $this->assess(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE]);

        self::assertTrue($assessment->isEmpty());
    }

    public function testASolveAfterTheNewResultsDayIsNoWarning(): void
    {
        // A back-dated result: the other solve is from after it
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 2);

        $assessment = $this->assess(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE], daysAgo: 10);

        self::assertTrue($assessment->isEmpty());
    }

    public function testANewResultOlderThanTheHeldFirstTryIsNoticed(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 2, firstTry: true);

        self::assertTrue($this->assess(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE], daysAgo: 10)->isOlderThanHolds());
        self::assertFalse($this->assess(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE], daysAgo: 1)->isOlderThanHolds());
    }

    public function testAPrivateTeammateIsLeftOutCompletely(): void
    {
        // Not even a refusal may tell the viewer anything about them - their own conflicts page shows the duplicate
        $this->scenario->add(PlayerFixture::PLAYER_PRIVATE_USER_ID, daysAgo: 20);
        $this->scenario->add(PlayerFixture::PLAYER_PRIVATE_USER_ID, daysAgo: 10, firstTry: true);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);

        $assessment = $this->assess(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_PRIVATE]);

        self::assertTrue($assessment->isEmpty());
        self::assertFalse($assessment->blocks(FirstTryResolution::None));
    }

    public function testAPrivateTeammateWhoAllowsTheViewerCounts(): void
    {
        // PrivateProfileViewerFixture: PLAYER_PRIVATE lets PLAYER_WITH_FAVORITES see them
        $this->scenario->add(PlayerFixture::PLAYER_PRIVATE_USER_ID, daysAgo: 10, firstTry: true);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

        $assessment = $this->assess(PlayerFixture::PLAYER_WITH_FAVORITES, [PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_PRIVATE]);

        self::assertTrue($assessment->blocks(FirstTryResolution::None));
        self::assertSame(FirstTryNoticeLine::TEAMMATE, $assessment->holdLines[0]->kind);
        self::assertSame('Jane Smith', $assessment->holdLines[0]->person?->name);
    }

    public function testABlockedTeammateIsLeftOutCompletely(): void
    {
        $this->scenario->add(FirstTryScenario::ADMIN_USER_ID, daysAgo: 12);
        $this->scenario->add(FirstTryScenario::ADMIN_USER_ID, daysAgo: 10, firstTry: true);
        $this->scenario->block(PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);

        $assessment = $this->assess(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN]);

        self::assertTrue($assessment->isEmpty());
    }

    public function testTheViewersOwnPairResultWithAHiddenPartnerStillCounts(): void
    {
        $pair = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#player2'], daysAgo: 10, firstTry: true);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);

        $assessment = $this->assess(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE]);

        self::assertSame([$pair], $assessment->timeIdsToUnmark(FirstTryResolution::MoveHere));
        self::assertSame([''], $assessment->holdLines[0]->with, 'The partner is "a puzzler", never named');
    }

    public function testAnEditLeavesItselfOut(): void
    {
        $time = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 10, firstTry: true);

        $assessment = $this->assessor->assess(new FirstTryEntry(
            actorPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleId: FirstTryScenario::PUZZLE,
            memberPlayerIds: [PlayerFixture::PLAYER_WITH_STRIPE],
            solvedAt: $this->scenario->daysAgo(10),
            editedTimeId: $time,
            previouslyFirstAttempt: true,
            previousMemberPlayerIds: [PlayerFixture::PLAYER_WITH_STRIPE],
        ));

        self::assertTrue($assessment->isEmpty());
    }

    public function testAnOldDuplicateIsToleratedWhenTheEditChangesNeitherTagNorPeople(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 12, firstTry: true);
        $edited = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 10, firstTry: true);

        $unchanged = $this->assessEdit($edited, [PlayerFixture::PLAYER_WITH_STRIPE], previouslyFirstAttempt: true);

        self::assertTrue($unchanged->hasHolds());
        self::assertTrue($unchanged->tolerated);
        self::assertFalse($unchanged->blocks(FirstTryResolution::None));

        $newlyTicked = $this->assessEdit($edited, [PlayerFixture::PLAYER_WITH_STRIPE], previouslyFirstAttempt: false);

        self::assertFalse($newlyTicked->tolerated);
        self::assertTrue($newlyTicked->blocks(FirstTryResolution::None));
    }

    public function testAddingSomebodyToAnOldDuplicateIsNotTolerated(): void
    {
        $this->scenario->add(FirstTryScenario::ADMIN_USER_ID, daysAgo: 12, firstTry: true);
        $edited = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 10, firstTry: true);

        $assessment = $this->assessEdit($edited, [PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN], previouslyFirstAttempt: true);

        self::assertFalse($assessment->tolerated);
        self::assertTrue($assessment->blocks(FirstTryResolution::None));
    }

    /**
     * @param list<string> $members
     */
    private function assess(string $viewer, array $members, int $daysAgo = 0): FirstTryAssessment
    {
        return $this->assessor->assess(new FirstTryEntry(
            actorPlayerId: $viewer,
            puzzleId: FirstTryScenario::PUZZLE,
            memberPlayerIds: $members,
            solvedAt: $this->scenario->daysAgo($daysAgo),
        ));
    }

    /**
     * @param list<string> $members
     */
    private function assessEdit(string $timeId, array $members, bool $previouslyFirstAttempt): FirstTryAssessment
    {
        return $this->assessor->assess(new FirstTryEntry(
            actorPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleId: FirstTryScenario::PUZZLE,
            memberPlayerIds: $members,
            solvedAt: $this->scenario->daysAgo(10),
            editedTimeId: $timeId,
            previouslyFirstAttempt: $previouslyFirstAttempt,
            previousMemberPlayerIds: [PlayerFixture::PLAYER_WITH_STRIPE],
        ));
    }
}
