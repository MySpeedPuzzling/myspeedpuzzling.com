<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
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
        // The trial status line of the Menu's You card (MENU-01) and of the account menu's Membership row (NAV-05)
        self::assertCount(1, $crawler->filter('#site-menu .menu-you-status'));
        self::assertMatchesRegularExpression('/^Free trial · \d+ days left$/', trim($crawler->filter('#site-menu .menu-you-status')->text()));
        self::assertCount(1, $crawler->filter('.site-header .nav-account-membership .nav-account-status'));
        self::assertMatchesRegularExpression('/^Free trial · \d+ days left$/', trim($crawler->filter('.site-header .nav-account-membership .nav-account-status')->text()));
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

    /**
     * TEASE-01: the members modal's trial form carries an empty `placement` field and its membership link is the
     * modal controller's - members_modal_controller.js fills both with the teaser that opened the modal
     */
    public function testTheMembersModalPassesThePlacementOn(): void
    {
        $browser = $this->establishedPlayer();

        $crawler = $browser->request('GET', '/en/ladder');

        $modal = $crawler->filter('#membersExclusiveModal[data-controller="members-modal"]');
        self::assertCount(1, $modal);
        $field = $modal->filter('form input[name="placement"][data-members-modal-target="placement"]');
        self::assertCount(1, $field);
        self::assertSame('', $field->attr('value'));
        self::assertCount(1, $modal->filter('input[name="source"][value="members_modal"]'), 'The stored source stays members_modal');
        self::assertCount(1, $modal->filter('a[href="/en/membership"][data-members-modal-target="link"]'));

        // The membership page's own trial form has no placement - it is no teaser
        $crawler = $browser->request('GET', '/en/membership');
        self::assertCount(0, $crawler->filter('.free-trial-offer input[name="placement"]'));
    }

    /**
     * TEASE-01: a trial started from a teaser is one info line on the `navigation` channel with its placement; the
     * stored source is the form's (members_modal), nothing else is stored
     */
    public function testATrialFromATeaserLogsItsPlacementAndKeepsTheSource(): void
    {
        $browser = $this->establishedPlayer();
        $browser->disableReboot();
        $log = self::navigationLog();

        $this->start($browser, ['source' => 'members_modal', 'placement' => 'card.tier', 'return' => '/en/ladder']);
        $this->assertResponseRedirects('/en/ladder');

        $membership = $browser->getContainer()->get(MembershipRepository::class)->getByPlayerId(PlayerFixture::PLAYER_REGULAR);
        self::assertSame(FreeTrialSource::MembersModal, $membership->trialSource);

        self::assertSame([[
            'kind' => 'trial_start',
            'placement' => 'card.tier',
            'source' => 'members_modal',
        ]], array_map(static fn (LogRecord $record): array => $record->context, $log->getRecords()));
        self::assertSame('Free trial started from a members teaser', $log->getRecords()[0]->message);
        self::assertSame('INFO', $log->getRecords()[0]->level->getName());
    }

    /**
     * TEASE-01: a placement the server does not know is ignored - the trial starts as always, no line
     */
    public function testAnUnknownPlacementIsIgnored(): void
    {
        $browser = $this->establishedPlayer();
        $browser->disableReboot();
        $log = self::navigationLog();

        $this->start($browser, ['source' => 'members_modal', 'placement' => 'header.everything']);
        $this->assertResponseRedirects('/en/membership/free-trial');

        $membership = $browser->getContainer()->get(MembershipRepository::class)->getByPlayerId(PlayerFixture::PLAYER_REGULAR);
        self::assertSame(FreeTrialSource::MembersModal, $membership->trialSource);
        self::assertSame([], $log->getRecords());
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
        // A member gets no locks: the modal's teaser measurement is left out of their pages (TEASE-01)
        self::assertCount(1, $crawler->filter('#membersExclusiveModal'));
        self::assertCount(0, $crawler->filter('#membersExclusiveModal[data-controller], #membersExclusiveModal [data-members-modal-target]'));

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

    /**
     * A TestHandler pushed onto the `navigation` logger of the current container (the client must not reboot)
     */
    private static function navigationLog(): TestHandler
    {
        /** @var Logger $logger */
        $logger = self::getContainer()->get('monolog.logger.navigation');

        $handler = new TestHandler();
        $handler->setSkipReset(true);
        $logger->pushHandler($handler);

        return $handler;
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
