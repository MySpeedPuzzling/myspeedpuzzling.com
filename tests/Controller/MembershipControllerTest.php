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
        $this->assertSelectorTextContains('form[action="/en/membership/keep-plan"] button', 'Keep the yearly plan');
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
        $this->assertSelectorNotExists('form[action="/en/membership/keep-plan"]');
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

    public function testKeepingThePlanWithoutABookedChangeIsRefusedBeforeStripeIsAsked(): void
    {
        $browser = self::createClient();
        $this->storePlan('puzzlership_yearly');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/membership');
        $token = $this->switchToken($browser, \SpeedPuzzling\Web\Controller\KeepMembershipPlanController::CSRF_TOKEN_ID);

        $browser->request('POST', '/en/membership/keep-plan', ['_token' => $token]);

        $this->assertResponseRedirects('/en/membership');
        $browser->followRedirect();
        $this->assertSelectorTextContains('body', "The booked change can't be undone here.");
    }

    public function testKeepingThePlanWithoutAValidTokenDoesNothing(): void
    {
        $browser = self::createClient();
        $this->storePlan('puzzlership_yearly', 'puzzlership_monthly', new \DateTimeImmutable('2027-05-01 10:00:00'));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('POST', '/en/membership/keep-plan', ['_token' => 'forged']);

        $this->assertResponseRedirects('/en/membership');
    }

    public function testANonMemberSeesThePlansAndEverythingAMembershipUnlocks(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/membership');

        $this->assertResponseIsSuccessful();

        // One picker, monthly picked (steadier income - Jan's call), the button pointing at the monthly checkout
        self::assertCount(2, $crawler->filter('#membership-offer input[name="membership_plan"]'));
        $this->assertSelectorExists('#membership-offer input[value="monthly"][checked]');
        $this->assertSelectorNotExists('#membership-offer input[value="yearly"][checked]');
        $this->assertSelectorExists('#membership-offer a[data-membership-plan-target="button"][href$="/en/buy-membership"]');
        $this->assertSelectorExists('#membership-offer input[value="yearly"][data-url$="/en/buy-membership/yearly"]');

        // The four groups of the one benefits list, no links to features they cannot use yet
        self::assertCount(4, $crawler->filter('.membership-group'));
        $this->assertSelectorTextContains('.membership-groups', 'Multiscan');
        $this->assertSelectorTextContains('.membership-groups', 'Your own player code');
        $this->assertSelectorNotExists('.membership-benefit-link');
        $this->assertSelectorNotExists('.membership-shortcuts');
        $this->assertSelectorTextContains('.membership-free', 'Free for everyone, always');

        // Never-built features are gone for good
        $page = $crawler->filter('main')->text();
        self::assertStringNotContainsString('mypuzzler.com', $page);
        self::assertStringNotContainsString('Monthly and yearly reports', $page);
        self::assertStringNotContainsString('look forward to', $page);

        // Insights stay behind INSIGHTS_PUBLIC (off in tests) for players
        self::assertStringNotContainsString('Your strengths, close calls', $page);
    }

    public function testAMemberGetsShortcutsAndLinksToTheirFeatures(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/membership');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Thank you for being a member');
        $this->assertSelectorExists('.membership-shortcuts a[href="/en/multiscan"]');
        $this->assertSelectorExists('.membership-benefit-link[href="/en/multiscan"]');
        $this->assertSelectorNotExists('input[name="membership_plan"]');
        $this->assertSelectorNotExists('.membership-free');
        $this->assertSelectorNotExists('.membership-sticky');
        self::assertCount(0, $crawler->filter('.membership-closing'));
    }

    private function storePlan(string $lookupKey, null|string $scheduledLookupKey = null, null|\DateTimeImmutable $scheduledAt = null): void
    {
        $container = self::getContainer();
        $container->get(MembershipRepository::class)->get(MembershipFixture::MEMBERSHIP_ACTIVE)->rememberStripePrice($lookupKey, $scheduledLookupKey, $scheduledAt);
        $container->get(EntityManagerInterface::class)->flush();
    }

    private function switchToken(\Symfony\Bundle\FrameworkBundle\KernelBrowser $browser, string $tokenId = \SpeedPuzzling\Web\Controller\SwitchMembershipPlanController::CSRF_TOKEN_ID): string
    {
        // The membership page renders no form for these players, so the token comes from the session the page started
        $container = self::getContainer();
        $request = new \Symfony\Component\HttpFoundation\Request();
        $session = $browser->getRequest()->getSession();
        $request->setSession($session);
        $container->get(\Symfony\Component\HttpFoundation\RequestStack::class)->push($request);

        $token = $container->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class)
            ->getToken($tokenId)
            ->getValue();
        $session->save();

        return $token;
    }
}
