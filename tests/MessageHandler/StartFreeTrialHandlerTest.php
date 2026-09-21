<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\FreeTrialNotAvailable;
use SpeedPuzzling\Web\Message\StartFreeTrial;
use SpeedPuzzling\Web\Query\GetPlayerMembership;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Value\FreeTrial;
use SpeedPuzzling\Web\Value\FreeTrialSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class StartFreeTrialHandlerTest extends KernelTestCase
{
    use OverridesFeatureFlagEnv;

    protected function setUp(): void
    {
        $this->overrideFeatureFlagEnv('FREE_TRIAL_ENABLED', true);
    }

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testPlayerWithoutMembershipGetsTenDaysOfMembership(): void
    {
        $container = self::getContainer();
        $now = $container->get(ClockInterface::class)->now();

        self::assertTrue($container->get(GetPlayerProfile::class)->byUserId(PlayerFixture::PLAYER_REGULAR_USER_ID)->freeTrialAvailable);

        $container->get(MessageBusInterface::class)->dispatch(
            new StartFreeTrial(PlayerFixture::PLAYER_REGULAR, FreeTrialSource::OfferModal),
        );

        $membership = $container->get(MembershipRepository::class)->getByPlayerId(PlayerFixture::PLAYER_REGULAR);

        self::assertTrue($membership->isFreeTrial());
        self::assertSame(FreeTrialSource::OfferModal, $membership->trialSource);
        self::assertNull($membership->stripeSubscriptionId);
        self::assertNotNull($membership->trialEndsAt);
        self::assertEquals($membership->trialEndsAt, $membership->grantedUntil, 'The trial is a plain grant');
        self::assertEqualsWithDelta(
            $now->getTimestamp() + FreeTrial::DAYS * 86400,
            $membership->trialEndsAt->getTimestamp(),
            5,
        );

        $profile = $container->get(GetPlayerProfile::class)->byUserId(PlayerFixture::PLAYER_REGULAR_USER_ID);

        self::assertTrue($profile->activeMembership, 'Every membership gate reads this');
        self::assertFalse($profile->freeTrialAvailable);
        self::assertNotNull($profile->freeTrialEndsAt);

        $result = $container->get(GetPlayerMembership::class)->byId(PlayerFixture::PLAYER_REGULAR);

        self::assertTrue($result->isInFreeTrial($now));
        self::assertSame(FreeTrial::DAYS, $result->freeTrialDaysLeft($now));
    }

    public function testTrialCannotBeStartedTwice(): void
    {
        $messageBus = self::getContainer()->get(MessageBusInterface::class);
        $messageBus->dispatch(new StartFreeTrial(PlayerFixture::PLAYER_REGULAR, FreeTrialSource::MembershipPage));

        $this->expectFreeTrialNotAvailable(
            static fn () => $messageBus->dispatch(new StartFreeTrial(PlayerFixture::PLAYER_REGULAR, FreeTrialSource::MembershipPage)),
        );
    }

    public function testPlayerWhoHasMembershipCannotStartTrial(): void
    {
        $messageBus = self::getContainer()->get(MessageBusInterface::class);

        self::assertFalse(self::getContainer()->get(GetPlayerProfile::class)->byUserId(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID)->freeTrialAvailable);

        $this->expectFreeTrialNotAvailable(
            static fn () => $messageBus->dispatch(new StartFreeTrial(PlayerFixture::PLAYER_WITH_STRIPE, FreeTrialSource::MembershipPage)),
        );
    }

    public function testNothingStartsWhileTheFeatureIsSwitchedOff(): void
    {
        $this->overrideFeatureFlagEnv('FREE_TRIAL_ENABLED', false);
        $messageBus = self::getContainer()->get(MessageBusInterface::class);

        $this->expectFreeTrialNotAvailable(
            static fn () => $messageBus->dispatch(new StartFreeTrial(PlayerFixture::PLAYER_REGULAR, FreeTrialSource::MembershipPage)),
        );
    }

    private function expectFreeTrialNotAvailable(callable $dispatch): void
    {
        try {
            $dispatch();
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(FreeTrialNotAvailable::class, $e->getPrevious());

            return;
        }

        self::fail('FreeTrialNotAvailable was expected');
    }
}
