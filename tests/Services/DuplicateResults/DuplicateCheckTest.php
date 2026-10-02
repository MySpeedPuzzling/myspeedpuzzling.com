<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DuplicateResults;

use SpeedPuzzling\Web\Services\FirstTry\FirstTryAssessor;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\FirstTryScenario;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\DuplicateAssessment;
use SpeedPuzzling\Web\Value\DuplicateNoticeLine;
use SpeedPuzzling\Web\Value\FirstTryEntry;
use SpeedPuzzling\Web\Value\FirstTryResolution;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The "same time already saved" part of the form check (docs/features/duplicate-results.md, Layer 2), decided from
 * the rows the first-try rules read. FirstTryScenario saves 5:00:00 unless told otherwise.
 */
final class DuplicateCheckTest extends KernelTestCase
{
    private const int FIVE_HOURS = 5 * 3600;

    private FirstTryAssessor $assessor;
    private FirstTryScenario $scenario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->assessor = self::getContainer()->get(FirstTryAssessor::class);
        $this->scenario = new FirstTryScenario(self::getContainer());
    }

    public function testTheOwnSameTimeFromTheSameDayNeedsAConfirmation(): void
    {
        $twin = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID);

        $duplicates = $this->duplicates(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE]);

        self::assertTrue($duplicates->needsConfirmation());
        self::assertTrue($duplicates->blocks(false));
        self::assertFalse($duplicates->blocks(true));
        self::assertSame([], $duplicates->otherDayLines);
        self::assertCount(1, $duplicates->sameDayLines);

        $line = $duplicates->sameDayLines[0];
        self::assertSame(DuplicateNoticeLine::OWN, $line->kind);
        self::assertSame($twin, $line->time->timeId);
        self::assertTrue($line->savedToday);
        self::assertTrue($line->viewable);
    }

    public function testAnotherSecondIsNoDuplicate(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, time: '05:00:01');

        self::assertTrue($this->duplicates(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE])->isEmpty());
    }

    public function testTheSameTimeFromAnotherDayIsOnlyInformation(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 14);

        $duplicates = $this->duplicates(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE]);

        self::assertFalse($duplicates->needsConfirmation());
        self::assertFalse($duplicates->blocks(false));
        self::assertCount(1, $duplicates->otherDayLines);
        self::assertSame(DuplicateNoticeLine::OWN, $duplicates->otherDayLines[0]->kind);
    }

    public function testAResultBackDatedToTheSameDayCountsAsTheSameDay(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 20);

        $duplicates = $this->duplicates(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE], daysAgo: 20);

        self::assertTrue($duplicates->needsConfirmation());
        self::assertTrue($duplicates->sameDayLines[0]->savedToday, 'Saved today, for a day weeks ago');
    }

    public function testATeammatesCopyOfThePairIsNamedAfterWhoeverSavedIt(): void
    {
        // Admin saved the pair with Sarah; now Sarah adds the same pair
        $copy = $this->scenario->add(FirstTryScenario::ADMIN_USER_ID, ['#player4']);

        $duplicates = $this->duplicates(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN]);

        self::assertTrue($duplicates->needsConfirmation());
        $line = $duplicates->sameDayLines[0];
        self::assertSame(DuplicateNoticeLine::WITH_VIEWER, $line->kind);
        self::assertSame($copy, $line->time->timeId);
        self::assertSame('Admin User', $line->person?->name);
        self::assertTrue($line->time->isPair());
        self::assertTrue($line->viewable);
    }

    public function testATeammatesOwnResultWithTheSameTimeCounts(): void
    {
        // Admin's solo with the very time of the pair Sarah is adding with them
        $this->scenario->add(FirstTryScenario::ADMIN_USER_ID);

        $duplicates = $this->duplicates(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN]);

        self::assertTrue($duplicates->needsConfirmation());
        self::assertSame(DuplicateNoticeLine::TEAMMATE, $duplicates->sameDayLines[0]->kind);
        self::assertSame('Admin User', $duplicates->sameDayLines[0]->person?->name);
        self::assertTrue($duplicates->sameDayLines[0]->viewable);
    }

    public function testSomebodyOutsideTheResultIsNoConcern(): void
    {
        $this->scenario->add(FirstTryScenario::ADMIN_USER_ID);

        self::assertTrue($this->duplicates(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE])->isEmpty());
    }

    public function testAPrivateTeammateIsLeftOutCompletely(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_PRIVATE_USER_ID);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);

        $duplicates = $this->duplicates(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_PRIVATE]);

        self::assertTrue($duplicates->isEmpty());
        self::assertFalse($duplicates->blocks(false), 'Not even a refusal may tell the viewer about them');
    }

    public function testABlockedTeammateIsLeftOutCompletely(): void
    {
        $this->scenario->add(FirstTryScenario::ADMIN_USER_ID);
        $this->scenario->block(PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertTrue($this->duplicates(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN])->isEmpty());
    }

    public function testTheViewersOwnPairWithAHiddenPartnerStillCountsWithoutTheirName(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#player2']);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);

        $duplicates = $this->duplicates(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE]);

        self::assertTrue($duplicates->needsConfirmation());
        self::assertSame([''], $duplicates->sameDayLines[0]->with, 'The partner is "a puzzler", never named');
    }

    public function testAnEditLeavesItselfOut(): void
    {
        $edited = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID);

        self::assertTrue($this->editDuplicates($edited, previousSeconds: self::FIVE_HOURS)->isEmpty());
    }

    public function testAnEditThatChangesNothingOfAnOldTwinIsTolerated(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, comment: 'first copy');
        $edited = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, comment: 'second copy');

        $unchanged = $this->editDuplicates($edited, previousSeconds: self::FIVE_HOURS);

        self::assertCount(1, $unchanged->sameDayLines);
        self::assertTrue($unchanged->tolerated);
        self::assertFalse($unchanged->needsConfirmation(), 'A comment fix of an old copy must not record it as a real second solve');

        // Typing the very time of the other one is a new twin
        $changedTime = $this->editDuplicates($edited, previousSeconds: self::FIVE_HOURS + 1);

        self::assertFalse($changedTime->tolerated);
        self::assertTrue($changedTime->needsConfirmation());
    }

    public function testNeitherTagNorTimeCostsNoQueryAndSaysNothing(): void
    {
        $check = $this->assessor->check(new FirstTryEntry(
            actorPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleId: FirstTryScenario::PUZZLE,
            memberPlayerIds: [PlayerFixture::PLAYER_WITH_STRIPE],
            solvedAt: $this->scenario->daysAgo(0),
        ), firstAttempt: false);

        self::assertNull($check->firstTry);
        self::assertNull($check->duplicates);
    }

    public function testTheFormSentAgainAfterItsSaveIsNotChecked(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, firstTry: true, comment: 'first copy');
        $saved = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, comment: 'second copy');

        $check = $this->assessor->check(new FirstTryEntry(
            actorPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleId: FirstTryScenario::PUZZLE,
            memberPlayerIds: [PlayerFixture::PLAYER_WITH_STRIPE],
            solvedAt: $this->scenario->daysAgo(0),
            secondsToSolve: self::FIVE_HOURS,
            newTimeId: $saved,
        ), firstAttempt: true);

        self::assertNull($check->firstTry, 'The handler answers the resend with the saved result');
        self::assertNull($check->duplicates);
    }

    public function testTheFormSentAgainWithAnotherTimeIsCheckedLikeANewResult(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, firstTry: true, comment: 'first copy');
        $saved = $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, comment: 'second copy');

        $check = $this->assessor->check(new FirstTryEntry(
            actorPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleId: FirstTryScenario::PUZZLE,
            memberPlayerIds: [PlayerFixture::PLAYER_WITH_STRIPE],
            solvedAt: $this->scenario->daysAgo(0),
            secondsToSolve: self::FIVE_HOURS + 1,
            newTimeId: $saved,
        ), firstAttempt: true);

        self::assertNotNull($check->firstTry, 'Not the same entry - the first try held by the first copy counts');
        self::assertTrue($check->firstTry->blocks(FirstTryResolution::None));
    }

    public function testACopyOfAFirstTryIsNotOfferedToBecomeTheFirstTry(): void
    {
        $this->scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, firstTry: true);

        $check = $this->assessor->check($this->entry(PlayerFixture::PLAYER_WITH_STRIPE, [PlayerFixture::PLAYER_WITH_STRIPE], 0), firstAttempt: true);

        self::assertNotNull($check->firstTry);
        self::assertTrue($check->firstTry->blocks(FirstTryResolution::None));
        self::assertTrue($check->duplicateBlocks(false));
        self::assertFalse($check->firstTryBlocks(FirstTryResolution::None, false), 'The same-day twin is answered first');
        self::assertTrue($check->firstTryBlocks(FirstTryResolution::None, true), 'Another solve cannot be a second first try');
    }

    /**
     * @param list<string> $members
     */
    private function duplicates(string $viewer, array $members, int $daysAgo = 0): DuplicateAssessment
    {
        $duplicates = $this->assessor->check($this->entry($viewer, $members, $daysAgo), firstAttempt: false)->duplicates;
        self::assertNotNull($duplicates);

        return $duplicates;
    }

    /**
     * @param list<string> $members
     */
    private function entry(string $viewer, array $members, int $daysAgo): FirstTryEntry
    {
        return new FirstTryEntry(
            actorPlayerId: $viewer,
            puzzleId: FirstTryScenario::PUZZLE,
            memberPlayerIds: $members,
            solvedAt: $this->scenario->daysAgo($daysAgo),
            secondsToSolve: self::FIVE_HOURS,
        );
    }

    private function editDuplicates(string $timeId, int $previousSeconds): DuplicateAssessment
    {
        $duplicates = $this->assessor->check(new FirstTryEntry(
            actorPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleId: FirstTryScenario::PUZZLE,
            memberPlayerIds: [PlayerFixture::PLAYER_WITH_STRIPE],
            solvedAt: $this->scenario->daysAgo(0),
            editedTimeId: $timeId,
            previousMemberPlayerIds: [PlayerFixture::PLAYER_WITH_STRIPE],
            secondsToSolve: self::FIVE_HOURS,
            previousSecondsToSolve: $previousSeconds,
            previousSolvedAt: $this->scenario->daysAgo(0),
        ), firstAttempt: false)->duplicates;
        self::assertNotNull($duplicates);

        return $duplicates;
    }
}
