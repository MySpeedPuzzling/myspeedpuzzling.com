<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\MessengerMiddleware;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Message\AddComparisonSubject;
use SpeedPuzzling\Web\Message\CancelMembershipSubscription;
use SpeedPuzzling\Web\Message\ClearComparisonLineUp;
use SpeedPuzzling\Web\Message\UpdateMembershipSubscription;
use SpeedPuzzling\Web\Value\ComparisonKind;

final class SerializedByLockMessagesTest extends TestCase
{
    /**
     * TerminateMembershipDueToDisputeHandler still locks inside the handler (its subscription id
     * is only known after asking Stripe) - it stays mutually exclusive with these two only while
     * all three use the very same key.
     */
    public function testMembershipMessagesLockTheSubscriptionUnderTheSameKey(): void
    {
        self::assertSame('stripe-subscription-sub_123', (new UpdateMembershipSubscription('sub_123'))->lockKey());
        self::assertSame('stripe-subscription-sub_123', (new CancelMembershipSubscription('sub_123'))->lockKey());
    }

    /**
     * Every add of one owner waits for the previous one to commit, whatever it adds - the cap is counted per owner
     */
    public function testComparisonAddsLockTheOwnersLineUps(): void
    {
        $owner = '018d0000-0000-0000-0000-00000000000A';
        $key = (new AddComparisonSubject($owner, 'p-018d0000-0000-0000-0000-00000000000b'))->lockKey();

        self::assertSame('comparison-line-up-018d0000-0000-0000-0000-00000000000a', $key);
        self::assertSame($key, (new AddComparisonSubject(strtolower($owner), 't-018d0000-0000-0000-0000-00000000000c', '018d0000-0000-0000-0000-00000000000d'))->lockKey());
        self::assertNotSame($key, (new AddComparisonSubject('018d0000-0000-0000-0000-00000000000e', 'p-018d0000-0000-0000-0000-00000000000b'))->lockKey());

        // "Clear" waits for the adds of the same owner and they for it - an add never lands half way through a clear
        self::assertSame($key, (new ClearComparisonLineUp($owner, ComparisonKind::Pairs))->lockKey());
    }
}
