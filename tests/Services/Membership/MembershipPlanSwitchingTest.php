<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Membership;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Exceptions\MembershipPlanSwitchNotPossible;
use SpeedPuzzling\Web\Query\GetPlayerMembership;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Services\Membership\MembershipPlanSwitching;
use SpeedPuzzling\Web\Tests\DataFixtures\MembershipFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\Value\MembershipPlanSwitchEligibilityTest;
use Stripe\BillingPortal\Session;
use Stripe\Collection;
use Stripe\Service\BillingPortal\BillingPortalServiceFactory;
use Stripe\Service\BillingPortal\SessionService;
use Stripe\Service\PriceService;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Tests\Value\BookedPlanSwitchTest;
use Stripe\Service\SubscriptionScheduleService;
use Stripe\Service\SubscriptionService;
use Stripe\SubscriptionSchedule;
use Stripe\StripeClient;
use Stripe\Subscription;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MembershipPlanSwitchingTest extends KernelTestCase
{
    private const string CUSTOMER = 'cus_test_123456789';
    private const string SUBSCRIPTION = 'sub_test_123456789';

    /** @var list<array<string, mixed>> */
    private array $createdSessions = [];

    /** @var list<string> */
    private array $releasedSchedules = [];

    public function testMonthlyMemberGetsStripesConfirmationOfTheYearlyPrice(): void
    {
        $this->storePlan('puzzlership_monthly');

        $url = $this->switching($this->liveSubscription('puzzlership_monthly'))
            ->confirmationUrl(PlayerFixture::PLAYER_WITH_STRIPE, self::CUSTOMER, 'en');

        self::assertSame('https://billing.stripe.com/p/session/test_switch', $url);
        self::assertCount(1, $this->createdSessions);

        $session = $this->createdSessions[0];
        self::assertSame(self::CUSTOMER, $session['customer']);
        self::assertSame('bpc_plan_switch', $session['configuration']);
        self::assertSame('en', $session['locale']);
        $router = self::getContainer()->get(UrlGeneratorInterface::class);
        self::assertSame($router->generate('membership', ['_locale' => 'en'], UrlGeneratorInterface::ABSOLUTE_URL), $session['return_url']);
        self::assertSame([
            'type' => 'subscription_update_confirm',
            'subscription_update_confirm' => [
                'subscription' => self::SUBSCRIPTION,
                'items' => [['id' => 'si_switch', 'price' => 'price_puzzlership_yearly', 'quantity' => 1]],
            ],
            'after_completion' => [
                'type' => 'redirect',
                'redirect' => ['return_url' => $router->generate('membership_plan_switched', ['_locale' => 'en'], UrlGeneratorInterface::ABSOLUTE_URL)],
            ],
        ], $session['flow_data']);
    }

    public function testYearlyMemberGetsTheMonthlyPrice(): void
    {
        $this->storePlan('puzzlership_yearly');

        $this->switching($this->liveSubscription('puzzlership_yearly'))
            ->confirmationUrl(PlayerFixture::PLAYER_WITH_STRIPE, self::CUSTOMER, 'en');

        /** @var array{subscription_update_confirm: array{items: list<array{price: string}>}} $flow */
        $flow = $this->createdSessions[0]['flow_data'];
        self::assertSame('price_puzzlership_monthly', $flow['subscription_update_confirm']['items'][0]['price']);
    }

    public function testEarlyAdopterPriceNeverReachesStripe(): void
    {
        $this->storePlan('puzzlership_monthly_promo');

        $this->expectException(MembershipPlanSwitchNotPossible::class);

        try {
            $this->switching(null)->confirmationUrl(PlayerFixture::PLAYER_WITH_STRIPE, self::CUSTOMER, 'en');
        } finally {
            self::assertSame([], $this->createdSessions);
        }
    }

    public function testStaleCopyIsCaughtByTheLiveSubscription(): void
    {
        // Our copy still says monthly, Stripe already moved the subscription to the early adopter price
        $this->storePlan('puzzlership_monthly');

        $this->expectException(MembershipPlanSwitchNotPossible::class);

        try {
            $this->switching($this->liveSubscription('puzzlership_monthly_promo'))
                ->confirmationUrl(PlayerFixture::PLAYER_WITH_STRIPE, self::CUSTOMER, 'en');
        } finally {
            self::assertSame([], $this->createdSessions);
        }
    }

    public function testChangeBookedMeanwhileIsCaughtByTheLiveSubscription(): void
    {
        $this->storePlan('puzzlership_yearly');

        $this->expectException(MembershipPlanSwitchNotPossible::class);

        try {
            $this->switching($this->liveSubscription('puzzlership_yearly', ['schedule' => 'sub_sched_1']))
                ->confirmationUrl(PlayerFixture::PLAYER_WITH_STRIPE, self::CUSTOMER, 'en');
        } finally {
            self::assertSame([], $this->createdSessions);
        }
    }

    public function testAnotherCustomersSubscriptionIsRefused(): void
    {
        $this->storePlan('puzzlership_monthly');

        $this->expectException(MembershipPlanSwitchNotPossible::class);

        try {
            $this->switching($this->liveSubscription('puzzlership_monthly'))
                ->confirmationUrl(PlayerFixture::PLAYER_WITH_STRIPE, 'cus_somebody_else', 'en');
        } finally {
            self::assertSame([], $this->createdSessions);
        }
    }

    public function testPlayerWithoutMembershipIsRefused(): void
    {
        $this->expectException(MembershipPlanSwitchNotPossible::class);

        $this->switching(null)->confirmationUrl(PlayerFixture::PLAYER_REGULAR, self::CUSTOMER, 'en');
    }

    public function testSwitchIsOffWithoutAPortalConfiguration(): void
    {
        $this->storePlan('puzzlership_monthly');
        $switching = $this->switching(null, portalConfigurationId: '');

        self::assertFalse($switching->isAvailable());

        $this->expectException(MembershipPlanSwitchNotPossible::class);

        try {
            $switching->confirmationUrl(PlayerFixture::PLAYER_WITH_STRIPE, self::CUSTOMER, 'en');
        } finally {
            self::assertSame([], $this->createdSessions);
        }
    }

    public function testKeepingThePlanReleasesTheBookedSchedule(): void
    {
        $this->storePlan('puzzlership_yearly', 'puzzlership_monthly');
        $now = self::getContainer()->get(ClockInterface::class)->now()->getTimestamp();

        $this->switching(
            $this->liveSubscription('puzzlership_yearly', ['schedule' => 'sub_sched_keep']),
            schedule: $this->bookedSchedule($now),
        )->keepCurrentPlan(PlayerFixture::PLAYER_WITH_STRIPE, self::CUSTOMER);

        self::assertSame(['sub_sched_keep'], $this->releasedSchedules);
        self::assertSame([], $this->createdSessions);
    }

    public function testAScheduleNotBookedByOurSwitchIsLeftAlone(): void
    {
        $this->storePlan('puzzlership_yearly', 'puzzlership_monthly');
        $now = self::getContainer()->get(ClockInterface::class)->now()->getTimestamp();

        $this->expectException(MembershipPlanSwitchNotPossible::class);

        try {
            $this->switching(
                $this->liveSubscription('puzzlership_yearly', ['schedule' => 'sub_sched_keep']),
                schedule: $this->bookedSchedule($now, ['end_behavior' => 'cancel']),
            )->keepCurrentPlan(PlayerFixture::PLAYER_WITH_STRIPE, self::CUSTOMER);
        } finally {
            self::assertSame([], $this->releasedSchedules);
        }
    }

    public function testNothingToKeepWithoutABookedChange(): void
    {
        $this->storePlan('puzzlership_yearly');

        $this->expectException(MembershipPlanSwitchNotPossible::class);

        try {
            $this->switching(null)->keepCurrentPlan(PlayerFixture::PLAYER_WITH_STRIPE, self::CUSTOMER);
        } finally {
            self::assertSame([], $this->releasedSchedules);
        }
    }

    public function testNothingToKeepWhenStripeHasNoScheduleAnyMore(): void
    {
        $this->storePlan('puzzlership_yearly', 'puzzlership_monthly');

        $this->expectException(MembershipPlanSwitchNotPossible::class);

        try {
            $this->switching($this->liveSubscription('puzzlership_yearly'))->keepCurrentPlan(PlayerFixture::PLAYER_WITH_STRIPE, self::CUSTOMER);
        } finally {
            self::assertSame([], $this->releasedSchedules);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function bookedSchedule(int $now, array $overrides = []): SubscriptionSchedule
    {
        $periodEnd = $now + 300 * 86_400;

        return BookedPlanSwitchTest::schedule(array_merge([
            'subscription' => self::SUBSCRIPTION,
            'customer' => self::CUSTOMER,
        ], $overrides), phases: [
            BookedPlanSwitchTest::phase($now - 65 * 86_400, $periodEnd, 'puzzlership_yearly'),
            BookedPlanSwitchTest::phase($periodEnd, $periodEnd + 1, 'puzzlership_monthly'),
        ]);
    }

    private function storePlan(string $lookupKey, null|string $scheduledLookupKey = null): void
    {
        $container = self::getContainer();
        $changeAt = $scheduledLookupKey !== null ? $container->get(ClockInterface::class)->now()->modify('+300 days') : null;
        $container->get(MembershipRepository::class)->get(MembershipFixture::MEMBERSHIP_ACTIVE)->rememberStripePrice($lookupKey, $scheduledLookupKey, $changeAt);
        $container->get(EntityManagerInterface::class)->flush();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function liveSubscription(string $lookupKey, array $overrides = []): Subscription
    {
        return MembershipPlanSwitchEligibilityTest::subscription($lookupKey, ['id' => self::SUBSCRIPTION, 'customer' => self::CUSTOMER] + $overrides);
    }

    private function switching(null|Subscription $liveSubscription, string $portalConfigurationId = 'bpc_plan_switch', null|SubscriptionSchedule $schedule = null): MembershipPlanSwitching
    {
        $schedules = $this->createMock(SubscriptionScheduleService::class);
        // Cancelling a schedule cancels its subscription - never, whatever happens
        $schedules->expects(self::never())->method('cancel');
        if ($schedule === null) {
            $schedules->expects(self::never())->method('retrieve');
            $schedules->expects(self::never())->method('release');
        } else {
            $schedules->expects(self::atLeastOnce())->method('retrieve')->with($schedule->id, ['expand' => ['phases.items.price']])->willReturn($schedule);
            $schedules->method('release')->willReturnCallback(function (string $id) use ($schedule): SubscriptionSchedule {
                $this->releasedSchedules[] = $id;

                return $schedule;
            });
        }

        $subscriptions = $this->createMock(SubscriptionService::class);
        if ($liveSubscription === null) {
            $subscriptions->expects(self::never())->method('retrieve');
        } else {
            $subscriptions->expects(self::once())->method('retrieve')->with(self::SUBSCRIPTION)->willReturn($liveSubscription);
        }

        $prices = $this->createStub(PriceService::class);
        $prices->method('all')->willReturnCallback(static function (array $params): Collection {
            /** @var array{lookup_keys: list<string>} $params */
            $lookupKey = $params['lookup_keys'][0];

            return Collection::constructFrom([
                'object' => 'list',
                'data' => [['id' => 'price_' . $lookupKey, 'object' => 'price', 'lookup_key' => $lookupKey]],
            ]);
        });

        $sessions = $this->createStub(SessionService::class);
        $sessions->method('create')->willReturnCallback(function (array $params): Session {
            /** @var array<string, mixed> $params */
            $this->createdSessions[] = $params;

            return Session::constructFrom(['id' => 'bps_test', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/test_switch']);
        });

        $billingPortal = $this->createStub(BillingPortalServiceFactory::class);
        $billingPortal->method('__get')->willReturnCallback(fn (string $name) => $name === 'sessions' ? $sessions : null);

        $stripeClient = $this->createStub(StripeClient::class);
        $stripeClient->method('__get')->willReturnCallback(
            fn (string $name) => match ($name) {
                'subscriptions' => $subscriptions,
                'prices' => $prices,
                'billingPortal' => $billingPortal,
                'subscriptionSchedules' => $schedules,
                default => null,
            },
        );

        $container = self::getContainer();

        return new MembershipPlanSwitching(
            $stripeClient,
            $container->get(GetPlayerMembership::class),
            $container->get(UrlGeneratorInterface::class),
            $container->get(ClockInterface::class),
            new NullLogger(),
            $portalConfigurationId,
        );
    }
}
