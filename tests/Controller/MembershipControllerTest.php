<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\ClaimVoucher;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Repository\VoucherRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\MembershipFixture;
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

    public function testSubscriberSeesTheVoucherDiscountOnTheirSubscription(): void
    {
        $browser = self::createClient();
        $container = self::getContainer();

        $voucher = $container->get(VoucherRepository::class)->getByCode(VoucherFixture::VOUCHER_PERCENTAGE_AVAILABLE_CODE);
        $voucher->setStripeCouponId('coupon_test_page');
        $container->get(MembershipRepository::class)->get(MembershipFixture::MEMBERSHIP_ACTIVE)->stripeDiscountCouponId = 'coupon_test_page';
        $container->get(EntityManagerInterface::class)->flush();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/membership');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.card-body', sprintf('%d%% discount on every payment', $voucher->percentageDiscount));
        $this->assertSelectorTextContains('.card-body', VoucherFixture::VOUCHER_PERCENTAGE_AVAILABLE_CODE);
    }

    public function testCouponWithoutAVoucherIsNotPresentedAsOne(): void
    {
        $browser = self::createClient();
        $container = self::getContainer();

        $container->get(MembershipRepository::class)->get(MembershipFixture::MEMBERSHIP_ACTIVE)->stripeDiscountCouponId = 'coupon_made_in_dashboard';
        $container->get(EntityManagerInterface::class)->flush();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/membership');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextNotContains('.card-body', 'discount on every payment');
    }
}
