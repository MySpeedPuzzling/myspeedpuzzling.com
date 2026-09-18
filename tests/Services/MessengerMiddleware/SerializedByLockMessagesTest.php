<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\MessengerMiddleware;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Message\CancelMembershipSubscription;
use SpeedPuzzling\Web\Message\UpdateMembershipSubscription;

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
}
