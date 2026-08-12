<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReferralDetailControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/referrals/' . PlayerFixture::PLAYER_REGULAR);

        $this->assertResponseRedirects('/login?return=/admin/referrals/' . PlayerFixture::PLAYER_REGULAR);
    }

    public function testAdminSeesAffiliateDetail(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/admin/referrals/' . PlayerFixture::PLAYER_REGULAR);

        $this->assertResponseIsSuccessful();
    }

    public function testUnknownPlayerGives404(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/admin/referrals/019f9999-9999-9999-9999-999999999999');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testSuspendNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/admin/referrals/' . PlayerFixture::PLAYER_REGULAR . '/suspend');

        $this->assertResponseRedirects('/login');
    }

    public function testUnsuspendNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/admin/referrals/' . PlayerFixture::PLAYER_WITH_STRIPE . '/unsuspend');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanSuspendAffiliate(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('POST', '/admin/referrals/' . PlayerFixture::PLAYER_REGULAR . '/suspend');

        $this->assertResponseRedirects('/admin/referrals/' . PlayerFixture::PLAYER_REGULAR);

        $player = $browser->getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR);
        self::assertTrue($player->referralProgramSuspended);
    }
}
