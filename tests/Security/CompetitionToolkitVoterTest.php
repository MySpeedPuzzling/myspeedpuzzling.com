<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Who may open the competition toolkit's settings (COMPETITION_TOOLKIT_MANAGE): exactly COMPETITION_EDIT - every
 * organiser of the event, admins on every event. PLAYER_WITH_STRIPE organises the Results Cup; nobody but admins
 * organises the online test cup.
 */
final class CompetitionToolkitVoterTest extends KernelTestCase
{
    public function testAdminsManageTheToolkitOfEveryEvent(): void
    {
        self::assertTrue($this->isGranted(PlayerFixture::PLAYER_ADMIN, OfficialResultsFixture::COMPETITION_RESULTS_CUP));
        self::assertTrue($this->isGranted(PlayerFixture::PLAYER_ADMIN, OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP));
    }

    public function testAnOrganiserManagesTheToolkitOfTheirEvent(): void
    {
        self::assertTrue($this->isGranted(PlayerFixture::PLAYER_WITH_STRIPE, OfficialResultsFixture::COMPETITION_RESULTS_CUP));
    }

    public function testNobodyManagesTheToolkitOfEventsTheyDoNotOrganise(): void
    {
        self::assertFalse($this->isGranted(PlayerFixture::PLAYER_REGULAR, OfficialResultsFixture::COMPETITION_RESULTS_CUP));
        self::assertFalse($this->isGranted(PlayerFixture::PLAYER_WITH_STRIPE, OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP));
    }

    public function testNobodySignedInManagesNothing(): void
    {
        self::bootKernel();

        self::assertFalse(self::getContainer()->get(AuthorizationCheckerInterface::class)->isGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, OfficialResultsFixture::COMPETITION_RESULTS_CUP));
    }

    public function testTheSubjectIsACompetitionId(): void
    {
        self::bootKernel();
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);

        self::assertFalse(self::getContainer()->get(AuthorizationCheckerInterface::class)->isGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE));
    }

    private function isGranted(string $playerId, string $competitionId): bool
    {
        if (self::$booted === false) {
            self::bootKernel();
        }

        TestingViewer::signIn(self::getContainer(), $playerId);

        return self::getContainer()->get(AuthorizationCheckerInterface::class)->isGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $competitionId);
    }
}
