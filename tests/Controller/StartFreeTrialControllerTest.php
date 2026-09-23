<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\FreeTrialConditions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\FreeTrialSource;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StartFreeTrialControllerTest extends WebTestCase
{
    use FreeTrialConditions;

    private const string ENDPOINT = '/en/membership/start-free-trial';

    public function testMembershipPageOffersTheTrialAndStartingItLandsOnTheWelcomePage(): void
    {
        $browser = $this->establishedPlayer();

        $crawler = $browser->request('GET', '/en/membership');
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.free-trial-offer form[action="' . self::ENDPOINT . '"]'));

        $this->start($browser, ['source' => 'membership_page']);
        $this->assertResponseRedirects('/en/membership/free-trial');

        $membership = $browser->getContainer()->get(MembershipRepository::class)->getByPlayerId(PlayerFixture::PLAYER_REGULAR);
        self::assertSame(FreeTrialSource::MembershipPage, $membership->trialSource);

        $crawler = $browser->followRedirect();
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('Your free trial is on!', $crawler->filter('h1')->text());

        // While it runs: no second offer, but the way to become a member stays on the page
        $crawler = $browser->request('GET', '/en/membership');
        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.free-trial-offer'));
        self::assertStringContainsString('Your free trial is running', $crawler->filter('.card-body')->first()->text());
        self::assertGreaterThan(0, $crawler->filter('a[href*="/buy-membership"], a[href*="buy"]')->count());
        self::assertCount(1, $crawler->filter('.topbar-link .badge'));
    }

    public function testFromAModalThePlayerComesBackToThePageTheyWereOn(): void
    {
        $browser = $this->establishedPlayer();

        $crawler = $browser->request('GET', '/en/ladder');
        self::assertCount(1, $crawler->filter('#membersExclusiveModal input[name="source"][value="members_modal"]'), 'The members modal offers the trial to whoever can start it');

        $this->start($browser, ['source' => 'members_modal', 'return' => '/en/ladder?x=1']);
        $this->assertResponseRedirects('/en/ladder?x=1');

        $crawler = $browser->followRedirect();
        self::assertCount(0, $crawler->filter('#membersExclusiveModal input[name="source"]'), 'Nothing left to offer');
    }

    public function testReturnUrlIsNeverTrustedToLeaveTheSite(): void
    {
        $browser = $this->establishedPlayer();

        $this->start($browser, ['source' => 'offer_modal', 'return' => '//evil.example/path']);

        $this->assertResponseRedirects('/en/membership/free-trial');
    }

    public function testMemberIsToldItIsNotForThemAndNothingChanges(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/membership');
        self::assertCount(0, $crawler->filter('.free-trial-offer'));
        self::assertCount(0, $crawler->filter('#membersExclusiveModal input[name="source"]'));

        $this->start($browser, ['source' => 'membership_page']);
        $this->assertResponseRedirects('/en/membership');

        $membership = $browser->getContainer()->get(MembershipRepository::class)->getByPlayerId(PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertFalse($membership->isFreeTrial());
    }

    public function testSecondClickDoesNotStartASecondTrial(): void
    {
        $browser = $this->establishedPlayer();

        $this->start($browser, ['source' => 'membership_page']);
        $first = $browser->getContainer()->get(MembershipRepository::class)->getByPlayerId(PlayerFixture::PLAYER_REGULAR);
        $firstEnd = $first->trialEndsAt?->format('Y-m-d H:i:s');

        $this->start($browser, ['source' => 'membership_page']);
        $this->assertResponseRedirects('/en/membership');

        $again = $browser->getContainer()->get(MembershipRepository::class)->getByPlayerId(PlayerFixture::PLAYER_REGULAR);
        self::assertSame($firstEnd, $again->trialEndsAt?->format('Y-m-d H:i:s'));
    }

    public function testForgedRequestStartsNothing(): void
    {
        $browser = $this->establishedPlayer();

        $browser->request('POST', self::ENDPOINT, ['_token' => 'csrf-token', 'source' => 'membership_page'], server: ['HTTP_ORIGIN' => 'https://evil.example']);

        $this->assertResponseRedirects('/en/membership');
        $this->assertNoMembership($browser, PlayerFixture::PLAYER_REGULAR);
    }

    public function testGuestIsSentToSignIn(): void
    {
        $browser = self::createClient();

        $this->start($browser, ['source' => 'membership_page']);

        $this->assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testWelcomePageIsOnlyForARunningTrial(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/membership/free-trial');

        $this->assertResponseRedirects('/en/membership');
    }

    public function testPlayerInTheirFirstWeekIsToldFromWhen(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/membership');
        $this->assertResponseIsSuccessful();

        $card = $crawler->filter('.free-trial-offer');
        self::assertCount(1, $card);
        self::assertCount(0, $card->filter('form'), 'Nothing to click yet');
        self::assertStringContainsString('once your account is 7 days old', $card->text());

        // The members modal says the same instead of offering a button that would not work
        self::assertCount(0, $crawler->filter('#membersExclusiveModal form'));
        self::assertStringContainsString('once your account is 7 days old', $crawler->filter('#membersExclusiveModal')->text());

        $this->start($browser, ['source' => 'membership_page']);
        $this->assertResponseRedirects('/en/membership');
        $this->assertNoMembership($browser, PlayerFixture::PLAYER_REGULAR);
    }

    private function establishedPlayer(): KernelBrowser
    {
        $browser = self::createClient();
        $this->registeredDaysAgo($browser->getContainer()->get(Connection::class), PlayerFixture::PLAYER_REGULAR, 8);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        return $browser;
    }

    /**
     * @param array<string, string> $fields
     */
    private function start(KernelBrowser $browser, array $fields): void
    {
        // Stateless CSRF: any token, as long as the request comes from our own origin
        $browser->request('POST', self::ENDPOINT, ['_token' => 'csrf-token'] + $fields, server: ['HTTP_ORIGIN' => 'http://localhost']);
    }

    private function assertNoMembership(KernelBrowser $browser, string $playerId): void
    {
        $found = true;

        try {
            $browser->getContainer()->get(MembershipRepository::class)->getByPlayerId($playerId);
        } catch (MembershipNotFound) {
            $found = false;
        }

        self::assertFalse($found, 'No membership was expected');
    }
}
