<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The competition toolkit's settings hub (R-review-and-plan.md §1.4) and its one link on the event's edit page: only for
 * COMPETITION_TOOLKIT_MANAGE - admins always, the event's organisers while COMPETITION_TOOLKIT_FOR_ORGANISERS is on.
 */
final class CompetitionToolkitHubTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    private const string FLAG = 'COMPETITION_TOOLKIT_FOR_ORGANISERS';
    private const string HUB = '/en/manage-event-toolkit/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string EDIT = '/en/edit-event/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    // The Results Cup's organiser - not an admin
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testAnAdminGetsTheHubWithEveryCardWhileTheFlagIsOff(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);

        $page = $browser->request('GET', self::HUB);

        self::assertResponseIsSuccessful();
        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertSame(
            ['competitor-pages', 'displays', 'standings', 'round-formats', 'judges'],
            $page->filter('[data-toolkit-card]')->each(static fn ($card): string => (string) $card->attr('data-toolkit-card')),
        );
        // A line per round, in the schedule's order
        self::assertSame(
            [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B, OfficialResultsFixture::ROUND_PAIRS, OfficialResultsFixture::ROUND_FINAL, OfficialResultsFixture::ROUND_PAIRS_FINAL],
            $page->filter('[data-toolkit-round]')->each(static fn ($round): string => (string) $round->attr('data-toolkit-round')),
        );
        self::assertCount(1, $page->filter('[data-toolkit-referees-link]'));
        self::assertCount(1, $page->filter('meta[name="robots"][content="noindex, nofollow"]'));
    }

    public function testAnEventWithoutRoundsLeadsToItsRounds(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);

        $page = $browser->request('GET', '/en/manage-event-toolkit/' . CompetitionFixture::COMPETITION_UNAPPROVED);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-toolkit-no-rounds]'));
        self::assertCount(0, $page->filter('[data-toolkit-round]'));
    }

    public function testAnOrganiserWithoutTheFlagGetsNeitherTheHubNorTheLink(): void
    {
        $browser = $this->browser(flag: false, player: self::ORGANISER);

        $browser->request('GET', self::HUB);
        self::assertResponseStatusCodeSame(403);

        $edit = $browser->request('GET', self::EDIT);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $edit->filter('[data-competition-toolkit-link]'));
    }

    public function testTheFlagOpensTheHubAndTheLinkToTheOrganiser(): void
    {
        $browser = $this->browser(flag: true, player: self::ORGANISER);

        $edit = $browser->request('GET', self::EDIT);
        self::assertResponseIsSuccessful();
        $link = $edit->filter('[data-competition-toolkit-link]');
        self::assertCount(1, $link);
        self::assertStringStartsWith(self::HUB . '?', (string) $link->attr('href'));

        $browser->request('GET', self::HUB);
        self::assertResponseIsSuccessful();
    }

    public function testTheFlagOpensNothingToOthers(): void
    {
        $browser = $this->browser(flag: true, player: PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::HUB);
        self::assertResponseStatusCodeSame(403);
    }

    public function testNobodySignedInIsSentToSignIn(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, true);
        $browser = self::createClient();

        $browser->request('GET', self::HUB);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testAnAdminSeesTheLinkOnTheEditPage(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);

        $edit = $browser->request('GET', self::EDIT);

        self::assertCount(1, $edit->filter('[data-competition-toolkit-link]'));
    }

    private function browser(bool $flag, string $player): KernelBrowser
    {
        // Before the kernel boots - the flag is a container parameter
        $this->overrideFeatureFlagEnv(self::FLAG, $flag);

        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $player);

        return $browser;
    }
}
