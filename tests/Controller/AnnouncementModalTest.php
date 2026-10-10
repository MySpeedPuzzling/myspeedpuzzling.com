<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\AnnouncementModals\ResolveAnnouncementModal;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\FreeTrialConditions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * The one-time announcement modals (docs/features/announcement-modals.md) - "try membership for free" and the XP
 * launch reveal: the right audience, the right pages, and never a second time. Every fixture player has seen the XP
 * launch modal long ago (XpLaunchRevealSeenFixture); its tests take that back first.
 */
final class AnnouncementModalTest extends WebTestCase
{
    use FreeTrialConditions;

    private const string MODAL = '#announcement-modal';
    private const string ORDINARY_PAGE = '/en/faq';

    public function testEstablishedPlayerWithoutMembershipSeesItExactlyOnce(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR, registeredDaysAgo: 7, andHours: 1);

        $crawler = $browser->request('GET', self::ORDINARY_PAGE);
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(self::MODAL));
        self::assertCount(1, $crawler->filter(self::MODAL . ' form[action="/en/membership/start-free-trial"] input[name="source"][value="offer_modal"]'));
        self::assertSame(self::ORDINARY_PAGE, $crawler->filter(self::MODAL . ' input[name="return"]')->attr('value'));

        foreach ([self::ORDINARY_PAGE, '/en/hub', '/en/ladder'] as $laterPage) {
            $crawler = $browser->request('GET', $laterPage);
            $this->assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter(self::MODAL), "Never again, not even on {$laterPage}");
        }

        self::assertSame(1, $this->impressions($browser, PlayerFixture::PLAYER_REGULAR));
    }

    public function testPlayerInTheirFirstWeekIsLeftAlone(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR, registeredDaysAgo: 6, andHours: 23);

        $crawler = $browser->request('GET', self::ORDINARY_PAGE);

        self::assertCount(0, $crawler->filter(self::MODAL));
        self::assertSame(0, $this->impressions($browser, PlayerFixture::PLAYER_REGULAR), 'Nothing is used up - the modal comes later');
    }

    public function testMembersNeverSeeIt(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE, registeredDaysAgo: 40);

        $crawler = $browser->request('GET', self::ORDINARY_PAGE);

        self::assertCount(0, $crawler->filter(self::MODAL));
        self::assertSame(0, $this->impressions($browser, PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testPagesInTheMiddleOfSomethingAreNotInterruptedAndDoNotUseTheModalUp(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR, registeredDaysAgo: 40);

        foreach (['/en/membership', '/en/edit-profile', self::ORDINARY_PAGE . '?return=/en/ladder'] as $quietPage) {
            $crawler = $browser->request('GET', $quietPage);
            $this->assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter(self::MODAL), "No modal on {$quietPage}");
        }

        self::assertSame(0, $this->impressions($browser, PlayerFixture::PLAYER_REGULAR));

        $crawler = $browser->request('GET', self::ORDINARY_PAGE);
        self::assertCount(1, $crawler->filter(self::MODAL), 'It waited for a page that can take it');
    }

    /**
     * An organiser running their event (the participants sheet, the results desk, check-in, ...) is in the middle of
     * something too (review B-m1).
     */
    public function testTheOrganisersToolsAreNotInterrupted(): void
    {
        // PLAYER_REGULAR organises this event
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR, registeredDaysAgo: 40);

        $crawler = $browser->request('GET', '/en/participants-sheet/' . CompetitionFixture::COMPETITION_UNAPPROVED);
        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(self::MODAL));
        self::assertSame(0, $this->impressions($browser, PlayerFixture::PLAYER_REGULAR));

        $crawler = $browser->request('GET', self::ORDINARY_PAGE);
        self::assertCount(1, $crawler->filter(self::MODAL), 'It waited for a page that can take it');
    }

    public function testEveryQuietRouteExists(): void
    {
        $routes = self::getContainer()->get(RouterInterface::class)->getRouteCollection();

        foreach (ResolveAnnouncementModal::QUIET_ROUTES as $route) {
            // Localized routes are registered per locale (`<name>.en`, ...) with the name as their canonical route
            self::assertTrue($routes->get($route) !== null || $routes->get($route . '.en') !== null, "Route {$route} does not exist");
        }
    }

    public function testNativeAppsAndTurboFramesGetNothing(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR, registeredDaysAgo: 40);

        $crawler = $browser->request('GET', self::ORDINARY_PAGE, server: ['HTTP_USER_AGENT' => 'MySpeedPuzzling iOS']);
        self::assertCount(0, $crawler->filter(self::MODAL));

        $crawler = $browser->request('GET', self::ORDINARY_PAGE, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        self::assertCount(0, $crawler->filter(self::MODAL));

        self::assertSame(0, $this->impressions($browser, PlayerFixture::PLAYER_REGULAR));
    }

    public function testGuestsGetNothing(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::ORDINARY_PAGE);

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(self::MODAL));
    }

    public function testBrowserReportConfirmsAnImpressionButNeverCreatesOne(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR, registeredDaysAgo: 40);

        // Nothing was displayed yet - a report out of the blue changes nothing
        $browser->request('POST', '/-/announcement-modal-seen', ['modal' => 'free_trial_offer']);
        $this->assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->impressions($browser, PlayerFixture::PLAYER_REGULAR));

        $browser->request('GET', self::ORDINARY_PAGE);
        self::assertSame(0, $this->seenImpressions($browser));

        $browser->request('POST', '/-/announcement-modal-seen', ['modal' => 'free_trial_offer']);
        $this->assertResponseStatusCodeSame(204);
        self::assertSame(1, $this->seenImpressions($browser));

        $browser->request('POST', '/-/announcement-modal-seen', ['modal' => 'no_such_modal']);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testAPlayerWithASolvingTimeGetsTheLaunchRevealExactlyOnce(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR, registeredDaysAgo: 1);
        $this->launchRevealNotSeenYet($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::ORDINARY_PAGE);
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(self::MODAL . '[data-autoshow-modal-name-value="xp_launch_reveal"]'));
        self::assertCount(1, $crawler->filter(self::MODAL . ' [data-controller="last-piece-reveal"] .lpr-stage'), 'The level assembles inside the modal');
        self::assertCount(1, $crawler->filter(self::MODAL . ' a[href="/en/membership"]'), 'A free player gets the member tease');
        self::assertCount(1, $crawler->filter(self::MODAL . ' a[href="/en/my/xp-reveal"]'));

        foreach ([self::ORDINARY_PAGE, '/en/hub'] as $laterPage) {
            $crawler = $browser->request('GET', $laterPage);
            self::assertCount(0, $crawler->filter(self::MODAL), "Never again, not even on {$laterPage}");
        }

        self::assertSame(1, $this->impressions($browser, PlayerFixture::PLAYER_REGULAR, 'xp_launch_reveal'));
    }

    public function testAMemberIsOfferedTheirAchievements(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE, registeredDaysAgo: 40);
        $this->launchRevealNotSeenYet($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $database = $browser->getContainer()->get(Connection::class);
        $database->executeStatement(
            "INSERT INTO badge (id, player_id, type, earned_at, tier) VALUES (gen_random_uuid(), :player, 'puzzles_solved', NOW(), 1)",
            ['player' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        $crawler = $browser->request('GET', self::ORDINARY_PAGE);

        self::assertCount(1, $crawler->filter(self::MODAL . '[data-autoshow-modal-name-value="xp_launch_reveal"]'));
        self::assertCount(1, $crawler->filter(self::MODAL . ' a[href="/en/my/xp-reveal"].btn-primary'), 'On to the achievement deck');
        self::assertCount(0, $crawler->filter(self::MODAL . ' a[href="/en/membership"]'));
    }

    public function testAPlayerWithoutSolvingTimesNeverGetsTheLaunchReveal(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR, registeredDaysAgo: 1);
        $this->launchRevealNotSeenYet($browser, PlayerFixture::PLAYER_REGULAR);
        // A player who logged nothing yet: no solving time of their own
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE puzzle_solving_time SET player_id = :other WHERE player_id = :player',
            ['other' => PlayerFixture::PLAYER_ADMIN, 'player' => PlayerFixture::PLAYER_REGULAR],
        );

        $crawler = $browser->request('GET', self::ORDINARY_PAGE);

        self::assertCount(0, $crawler->filter(self::MODAL));
        self::assertSame(0, $this->impressions($browser, PlayerFixture::PLAYER_REGULAR, 'xp_launch_reveal'));
    }

    public function testVisitingTheRevealPageFirstUsesTheLaunchModalUp(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR, registeredDaysAgo: 1);
        $this->launchRevealNotSeenYet($browser, PlayerFixture::PLAYER_REGULAR);

        // e.g. from the launch newsletter
        $crawler = $browser->request('GET', '/en/my/xp-reveal');
        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(self::MODAL), 'Never a modal on top of the reveal itself');

        $crawler = $browser->request('GET', self::ORDINARY_PAGE);
        self::assertCount(0, $crawler->filter(self::MODAL), 'The reveal was seen - no modal offering it again');
        self::assertSame(1, $this->impressions($browser, PlayerFixture::PLAYER_REGULAR, 'xp_launch_reveal'));
    }

    public function testTheLaunchRevealGoesFirstAndThePauseDoesNotHoldItBack(): void
    {
        // Old enough for the free trial offer too
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR, registeredDaysAgo: 40);
        $this->launchRevealNotSeenYet($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::ORDINARY_PAGE);
        self::assertCount(1, $crawler->filter(self::MODAL . '[data-autoshow-modal-name-value="xp_launch_reveal"]'), 'It wins over the free trial offer');

        // The free trial offer now waits for the pause after the launch modal
        $crawler = $browser->request('GET', '/en/hub');
        self::assertCount(0, $crawler->filter(self::MODAL));
        self::assertSame(0, $this->impressions($browser, PlayerFixture::PLAYER_REGULAR));
    }

    public function testTheLaunchRevealIgnoresThePauseAfterAnotherModal(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR, registeredDaysAgo: 40);
        $this->launchRevealNotSeenYet($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO player_modal_impression (id, player_id, modal, displayed_at) VALUES (gen_random_uuid(), :player, 'free_trial_offer', NOW() - INTERVAL '1 day')",
            ['player' => PlayerFixture::PLAYER_REGULAR],
        );

        $crawler = $browser->request('GET', self::ORDINARY_PAGE);

        self::assertCount(1, $crawler->filter(self::MODAL . '[data-autoshow-modal-name-value="xp_launch_reveal"]'));
    }

    public function testTheLaunchRevealWaitsForAPageThatCanTakeIt(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR, registeredDaysAgo: 1);
        $this->launchRevealNotSeenYet($browser, PlayerFixture::PLAYER_REGULAR);

        foreach (['/en/membership', '/en/edit-profile', '/en/my/achievement-reveals'] as $quietPage) {
            $crawler = $browser->request('GET', $quietPage);
            self::assertCount(0, $crawler->filter(self::MODAL), "No modal on {$quietPage}");
        }

        self::assertSame(0, $this->impressions($browser, PlayerFixture::PLAYER_REGULAR, 'xp_launch_reveal'));

        $crawler = $browser->request('GET', self::ORDINARY_PAGE);
        self::assertCount(1, $crawler->filter(self::MODAL . '[data-autoshow-modal-name-value="xp_launch_reveal"]'));
    }

    public function testAPlayerWhoSwitchedXpOffNeverGetsTheLaunchReveal(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR, registeredDaysAgo: 1);
        $this->launchRevealNotSeenYet($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET experience_system_opted_out = true WHERE id = :player',
            ['player' => PlayerFixture::PLAYER_REGULAR],
        );

        $crawler = $browser->request('GET', self::ORDINARY_PAGE);

        self::assertCount(0, $crawler->filter(self::MODAL));
    }

    private function launchRevealNotSeenYet(KernelBrowser $browser, string $playerId): void
    {
        $browser->getContainer()->get(Connection::class)->executeStatement(
            "DELETE FROM player_modal_impression WHERE player_id = :player AND modal = 'xp_launch_reveal'",
            ['player' => $playerId],
        );
    }

    private function signedIn(string $playerId, int $registeredDaysAgo, int $andHours = 0): KernelBrowser
    {
        $browser = self::createClient();

        // Fixture players are all registered "now" - which is exactly whom the modal must leave alone
        $this->registeredDaysAgo($browser->getContainer()->get(Connection::class), $playerId, $registeredDaysAgo, $andHours);

        TestingLogin::asPlayer($browser, $playerId);

        return $browser;
    }

    private function impressions(KernelBrowser $browser, string $playerId, string $modal = 'free_trial_offer'): int
    {
        $count = $browser->getContainer()->get(Connection::class)
            ->executeQuery(
                'SELECT COUNT(*) FROM player_modal_impression WHERE player_id = :id AND modal = :modal',
                ['id' => $playerId, 'modal' => $modal],
            )
            ->fetchOne();

        return is_numeric($count) ? (int) $count : -1;
    }

    private function seenImpressions(KernelBrowser $browser): int
    {
        $count = $browser->getContainer()->get(Connection::class)
            ->executeQuery('SELECT COUNT(*) FROM player_modal_impression WHERE seen_at IS NOT NULL')
            ->fetchOne();

        return is_numeric($count) ? (int) $count : -1;
    }
}
