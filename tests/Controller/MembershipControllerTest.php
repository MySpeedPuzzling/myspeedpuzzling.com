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

    public function testMonthlyMemberIsOfferedTheYearlyPlan(): void
    {
        $browser = self::createClient();
        $this->storePlan('puzzlership_monthly');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/membership');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.card-body', 'Monthly plan');
        $this->assertSelectorTextContains('.card-body', 'Pay yearly and get 2 months free.');
        $this->assertSelectorExists('form[action="/en/membership/switch-plan"][data-turbo="false"] button');
        $this->assertSelectorTextContains('form[action="/en/membership/switch-plan"] button', 'Switch to yearly');
    }

    public function testYearlyMemberIsOfferedTheMonthlyPlanFromTheEndOfTheYear(): void
    {
        $browser = self::createClient();
        $this->storePlan('puzzlership_yearly');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/membership');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.card-body', 'Yearly plan');
        $this->assertSelectorTextContains('form[action="/en/membership/switch-plan"] button', 'Switch to monthly');
        $this->assertSelectorTextContains('.card-body', 'Your paid year stays as it is. Monthly payments start on');
    }

    public function testBookedChangeIsShownInsteadOfTheButton(): void
    {
        $browser = self::createClient();
        $this->storePlan('puzzlership_yearly', 'puzzlership_monthly', new \DateTimeImmutable('2027-05-01 10:00:00'));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/membership');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.card-body', 'Moves to the monthly plan on May 1, 2027.');
        $this->assertSelectorNotExists('form[action="/en/membership/switch-plan"]');
    }

    public function testEarlyAdopterPriceGetsNoPlanSwitch(): void
    {
        $browser = self::createClient();
        $this->storePlan('puzzlership_monthly_promo');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/membership');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextNotContains('.card-body', 'Monthly plan');
        $this->assertSelectorNotExists('form[action="/en/membership/switch-plan"]');
        // The payment portal stays as it was
        $this->assertSelectorExists('a[href$="/billing-portal"]');
    }

    public function testSwitchWithoutAValidTokenDoesNothing(): void
    {
        $browser = self::createClient();
        $this->storePlan('puzzlership_monthly');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('POST', '/en/membership/switch-plan', ['_token' => 'forged']);

        $this->assertResponseRedirects('/en/membership');
    }

    public function testSwitchIsRefusedForAnEarlyAdopterBeforeStripeIsAsked(): void
    {
        $browser = self::createClient();
        $this->storePlan('puzzlership_monthly_promo');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/membership');
        $token = $this->switchToken($browser);

        $browser->request('POST', '/en/membership/switch-plan', ['_token' => $token]);

        $this->assertResponseRedirects('/en/membership');
        $browser->followRedirect();
        $this->assertSelectorTextContains('body', "Your plan can't be switched right now.");
    }

    public function testSwitchIsRefusedWithoutAMembership(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/membership');
        $token = $this->switchToken($browser);

        $browser->request('POST', '/en/membership/switch-plan', ['_token' => $token]);

        $this->assertResponseRedirects('/en/membership');
    }

    public function testReturnFromStripeWithoutAMembershipGoesBackToTheMembershipPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/membership/plan-switched');

        $this->assertResponseRedirects('/en/membership');
    }

    private function storePlan(string $lookupKey, null|string $scheduledLookupKey = null, null|\DateTimeImmutable $scheduledAt = null): void
    {
        $container = self::getContainer();
        $container->get(MembershipRepository::class)->get(MembershipFixture::MEMBERSHIP_ACTIVE)->rememberStripePrice($lookupKey, $scheduledLookupKey, $scheduledAt);
        $container->get(EntityManagerInterface::class)->flush();
    }

    private function switchToken(\Symfony\Bundle\FrameworkBundle\KernelBrowser $browser): string
    {
        // The membership page renders no form for these players, so the token comes from the session the page started
        $container = self::getContainer();
        $request = new \Symfony\Component\HttpFoundation\Request();
        $session = $browser->getRequest()->getSession();
        $request->setSession($session);
        $container->get(\Symfony\Component\HttpFoundation\RequestStack::class)->push($request);

        $token = $container->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class)
            ->getToken(\SpeedPuzzling\Web\Controller\SwitchMembershipPlanController::CSRF_TOKEN_ID)
            ->getValue();
        $session->save();

        return $token;
    }
}
