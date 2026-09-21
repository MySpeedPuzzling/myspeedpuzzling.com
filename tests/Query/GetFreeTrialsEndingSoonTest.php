<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\SendFreeTrialEndingReminder;
use SpeedPuzzling\Web\Message\StartFreeTrial;
use SpeedPuzzling\Web\Query\GetFreeTrialsEndingSoon;
use SpeedPuzzling\Web\Query\GetFreeTrialStatistics;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\FreeTrialConditions;
use SpeedPuzzling\Web\Value\FreeTrialSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class GetFreeTrialsEndingSoonTest extends KernelTestCase
{
    use FreeTrialConditions;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->registeredDaysAgo($container->get(Connection::class), PlayerFixture::PLAYER_REGULAR, 30);
        $this->now = $container->get(ClockInterface::class)->now();

        $container->get(MessageBusInterface::class)->dispatch(
            new StartFreeTrial(PlayerFixture::PLAYER_REGULAR, FreeTrialSource::MembershipPage),
        );
    }

    public function testTrialIsRemindedOnlyInItsLastThreeDays(): void
    {
        $query = self::getContainer()->get(GetFreeTrialsEndingSoon::class);

        self::assertSame([], $query->membershipIdsToRemind($this->now), 'Ten days to go');
        self::assertSame([], $query->membershipIdsToRemind($this->now->modify('+6 days')));
        self::assertCount(1, $query->membershipIdsToRemind($this->now->modify('+8 days')));
        self::assertSame([], $query->membershipIdsToRemind($this->now->modify('+11 days')), 'Over - nothing left to remind of');
    }

    public function testSubscribersAndVoucherHoldersAreLeftAlone(): void
    {
        $query = self::getContainer()->get(GetFreeTrialsEndingSoon::class);
        $database = self::getContainer()->get(Connection::class);
        $inLastDays = $this->now->modify('+8 days');

        $database->executeStatement("UPDATE membership SET granted_until = granted_until + interval '3 months' WHERE player_id = :id", ['id' => PlayerFixture::PLAYER_REGULAR]);
        self::assertSame([], $query->membershipIdsToRemind($inLastDays), 'A voucher claimed during the trial - nothing ends');

        $database->executeStatement("UPDATE membership SET granted_until = trial_ends_at, stripe_subscription_id = 'sub_x' WHERE player_id = :id", ['id' => PlayerFixture::PLAYER_REGULAR]);
        self::assertSame([], $query->membershipIdsToRemind($inLastDays), 'Already subscribed');
    }

    public function testReminderIsSentOnce(): void
    {
        $container = self::getContainer();
        $membership = $container->get(MembershipRepository::class)->getByPlayerId(PlayerFixture::PLAYER_REGULAR);

        $container->get(MessageBusInterface::class)->dispatch(new SendFreeTrialEndingReminder($membership->id->toString()));

        self::assertNotNull($membership->trialEndingReminderSentAt);
        self::assertSame([], $container->get(GetFreeTrialsEndingSoon::class)->membershipIdsToRemind($this->now->modify('+8 days')));
    }

    public function testStatisticsCountTrialsAndModalImpressions(): void
    {
        $container = self::getContainer();
        $statistics = $container->get(GetFreeTrialStatistics::class);

        $funnel = $statistics->funnel($this->now);

        self::assertSame(1, $funnel->started);
        self::assertSame(1, $funnel->running);
        self::assertSame(0, $funnel->ended);
        self::assertSame(0, $funnel->subscribedTotal);
        self::assertSame(['membership_page' => 1], $funnel->startedBySource);
        self::assertGreaterThan(0, $funnel->eligiblePlayers);

        self::assertSame(1, $statistics->funnel($this->now->modify('+11 days'))->ended);

        $container->get(Connection::class)->executeStatement(
            "INSERT INTO player_modal_impression (id, player_id, modal, displayed_at, seen_at) VALUES
                ('018d0000-0000-0000-0000-0000000000a1', :first, 'free_trial_offer', :now, :now),
                ('018d0000-0000-0000-0000-0000000000a2', :second, 'free_trial_offer', :now, NULL)",
            ['first' => PlayerFixture::PLAYER_REGULAR, 'second' => PlayerFixture::PLAYER_ADMIN, 'now' => $this->now->format('Y-m-d H:i:s')],
        );

        $modals = $statistics->modalImpressions($this->now->modify('+1 hour'));

        self::assertCount(1, $modals);
        self::assertSame('free_trial_offer', $modals[0]->modal);
        self::assertSame(2, $modals[0]->displayed);
        self::assertSame(1, $modals[0]->seen);
        self::assertSame(50, $modals[0]->seenPercent());
        self::assertSame(2, $modals[0]->displayedLast24Hours);
    }
}
