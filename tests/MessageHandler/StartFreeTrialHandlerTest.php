<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\FreeTrialNotAvailable;
use SpeedPuzzling\Web\Exceptions\FreeTrialNotUnlockedYet;
use SpeedPuzzling\Web\Message\StartFreeTrial;
use SpeedPuzzling\Web\Query\GetPlayerMembership;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\FreeTrialConditions;
use SpeedPuzzling\Web\Value\FreeTrial;
use SpeedPuzzling\Web\Value\FreeTrialSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class StartFreeTrialHandlerTest extends KernelTestCase
{
    use FreeTrialConditions;

    protected function setUp(): void
    {
        // Old enough and with plenty of puzzles logged - each test takes away what it is about
        $this->registeredDaysAgo(self::getContainer()->get(Connection::class), PlayerFixture::PLAYER_REGULAR, 8);
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

    public function testAccountYoungerThanAWeekHasToWait(): void
    {
        $container = self::getContainer();
        $this->registeredDaysAgo($container->get(Connection::class), PlayerFixture::PLAYER_REGULAR, 6, 23);

        $profile = $container->get(GetPlayerProfile::class)->byUserId(PlayerFixture::PLAYER_REGULAR_USER_ID);
        self::assertTrue($profile->freeTrialAvailable, 'Still theirs to have');
        self::assertFalse($profile->canStartFreeTrial(), 'Just not yet');
        self::assertNotNull($profile->freeTrialOldEnoughAt);
        self::assertSame(0, $profile->freeTrialLoggedPuzzlesMissing());

        $this->expectFailure(
            FreeTrialNotUnlockedYet::class,
            static fn () => $container->get(MessageBusInterface::class)->dispatch(new StartFreeTrial(PlayerFixture::PLAYER_REGULAR, FreeTrialSource::MembershipPage)),
        );
    }

    public function testFewerThanFiveLoggedPuzzlesHasToWait(): void
    {
        $container = self::getContainer();
        $this->keepLoggedPuzzles($container->get(Connection::class), PlayerFixture::PLAYER_REGULAR, 4);

        $profile = $container->get(GetPlayerProfile::class)->byUserId(PlayerFixture::PLAYER_REGULAR_USER_ID);
        self::assertFalse($profile->canStartFreeTrial());
        self::assertNull($profile->freeTrialOldEnoughAt, 'The age is fine - only the puzzles are told');
        self::assertSame(4, $profile->freeTrialLoggedPuzzles);
        self::assertSame(1, $profile->freeTrialLoggedPuzzlesMissing());

        $this->expectFailure(
            FreeTrialNotUnlockedYet::class,
            static fn () => $container->get(MessageBusInterface::class)->dispatch(new StartFreeTrial(PlayerFixture::PLAYER_REGULAR, FreeTrialSource::MembershipPage)),
        );

        self::assertSame(FreeTrial::MINIMUM_LOGGED_PUZZLES, $container->get(GetPlayerProfile::class)->byUserId(PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID)->freeTrialLoggedPuzzles, 'Never counted past the number that matters');
    }

    private function expectFreeTrialNotAvailable(callable $dispatch): void
    {
        $this->expectFailure(FreeTrialNotAvailable::class, $dispatch);
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    private function expectFailure(string $expected, callable $dispatch): void
    {
        try {
            $dispatch();
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf($expected, $e->getPrevious());

            return;
        }

        self::fail($expected . ' was expected');
    }
}
