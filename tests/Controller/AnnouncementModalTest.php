<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\FreeTrialConditions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The one-time "try membership for free" modal (docs/features/announcement-modals.md): the right
 * audience, the right pages, and never a second time.
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

    private function signedIn(string $playerId, int $registeredDaysAgo, int $andHours = 0): KernelBrowser
    {
        $browser = self::createClient();

        // Fixture players are all registered "now" - which is exactly whom the modal must leave alone
        $this->registeredDaysAgo($browser->getContainer()->get(Connection::class), $playerId, $registeredDaysAgo, $andHours);

        TestingLogin::asPlayer($browser, $playerId);

        return $browser;
    }

    private function impressions(KernelBrowser $browser, string $playerId): int
    {
        $count = $browser->getContainer()->get(Connection::class)
            ->executeQuery('SELECT COUNT(*) FROM player_modal_impression WHERE player_id = :id', ['id' => $playerId])
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
