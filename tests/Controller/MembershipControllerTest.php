<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Message\ClaimVoucher;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\VoucherFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class MembershipControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/membership');
        $this->assertResponseRedirects();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/membership');
        $this->assertResponseIsSuccessful();
    }

    public function testLifetimeMemberSeesLifetimeMembership(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(
            new ClaimVoucher(
                playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
                voucherCode: VoucherFixture::VOUCHER_LIFETIME_AVAILABLE_CODE,
            ),
        );

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $browser->request('GET', '/en/membership');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.card-body', 'Lifetime membership');
        $this->assertSelectorTextNotContains('.card-body', '2199');
        $this->assertSelectorNotExists('a[href$="/en/buy-membership/yearly"]');
    }

    public function testSubscriberCanOpenThePaymentPortal(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/membership');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('a[href$="/billing-portal"]');
    }
}
