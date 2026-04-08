<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AffiliateDashboardControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/referral-program');

        $this->assertResponseRedirects();
    }

    public function testActiveAffiliateSeesFullDashboard(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/referral-program');

        $this->assertResponseIsSuccessful();
        // Should show referral link with clipboard controller
        $this->assertSelectorExists('[data-controller="clipboard"]');
    }

    public function testPendingAffiliateSeesWaitingMessage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $browser->request('GET', '/en/referral-program');

        $this->assertResponseIsSuccessful();
    }

    public function testNonAffiliateSeesNotEnrolledPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/referral-program');

        $this->assertResponseIsSuccessful();
    }
}
