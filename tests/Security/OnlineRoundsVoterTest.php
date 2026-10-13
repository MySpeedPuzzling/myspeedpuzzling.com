<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use SpeedPuzzling\Web\Security\OnlineRoundsVoter;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineRoundsFeature;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Who may set up online timing (ONLINE_ROUNDS_MANAGE, docs/features/feature_flags.md "ONLINE_ROUNDS_FOR_ORGANISERS"):
 * an organiser of the event for whom the feature is on - admins always, other organisers only with the flag.
 */
final class OnlineRoundsVoterTest extends KernelTestCase
{
    use OverridesFeatureFlagEnv;

    private const string FLAG = 'ONLINE_ROUNDS_FOR_ORGANISERS';

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testAdminsManageOnlineRoundsWhileTheFlagIsOff(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);

        self::assertTrue($this->isGranted(PlayerFixture::PLAYER_ADMIN, OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP));
        // Admins have COMPETITION_EDIT on every event
        self::assertTrue($this->isGranted(PlayerFixture::PLAYER_ADMIN, OfficialResultsFixture::COMPETITION_RESULTS_CUP));
    }

    public function testAnOrganiserManagesOnlineRoundsOnlyWhileTheFlagIsOn(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        self::assertFalse($this->isGranted(PlayerFixture::PLAYER_WITH_STRIPE, OfficialResultsFixture::COMPETITION_RESULTS_CUP));
        self::assertFalse(self::getContainer()->get(OnlineRoundsFeature::class)->isAvailableTo(PlayerFixture::PLAYER_WITH_STRIPE));

        self::ensureKernelShutdown();
        $this->overrideFeatureFlagEnv(self::FLAG, true);
        self::assertTrue($this->isGranted(PlayerFixture::PLAYER_WITH_STRIPE, OfficialResultsFixture::COMPETITION_RESULTS_CUP));
        self::assertTrue(self::getContainer()->get(OnlineRoundsFeature::class)->isAvailableTo(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testTheFlagOpensNothingOfEventsThePlayerDoesNotOrganise(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, true);

        self::assertFalse($this->isGranted(PlayerFixture::PLAYER_REGULAR, OfficialResultsFixture::COMPETITION_RESULTS_CUP));
        self::assertFalse($this->isGranted(PlayerFixture::PLAYER_WITH_STRIPE, OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP));
    }

    public function testNobodySignedInManagesNothing(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, true);
        self::bootKernel();

        self::assertFalse(self::getContainer()->get(AuthorizationCheckerInterface::class)->isGranted(OnlineRoundsVoter::ONLINE_ROUNDS_MANAGE, OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP));
        self::assertFalse(self::getContainer()->get(OnlineRoundsFeature::class)->isAvailableTo(null));
    }

    public function testTheSubjectIsACompetitionId(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        self::bootKernel();
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);

        self::assertFalse(self::getContainer()->get(AuthorizationCheckerInterface::class)->isGranted(OnlineRoundsVoter::ONLINE_ROUNDS_MANAGE));
    }

    private function isGranted(string $playerId, string $competitionId): bool
    {
        if (self::$booted === false) {
            self::bootKernel();
        }

        TestingViewer::signIn(self::getContainer(), $playerId);

        return self::getContainer()->get(AuthorizationCheckerInterface::class)->isGranted(OnlineRoundsVoter::ONLINE_ROUNDS_MANAGE, $competitionId);
    }
}
