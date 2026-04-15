<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use SpeedPuzzling\Web\Value\EmailNotificationFrequency;

final class PlayerProfileMembershipTest extends TestCase
{
    /**
     * This is the exact scenario that caused the bug: billing_period_ends_at has passed
     * but ends_at is NULL (subscription not cancelled). Stripe hasn't sent the renewal
     * webhook yet due to invoice finalization delay.
     *
     * The membership must remain active because ends_at=NULL means "not cancelled."
     */
    public function testActiveSubscriptionDuringRenewalGap(): void
    {
        $now = new DateTimeImmutable('2026-04-01 02:00:00');

        $row = $this->createRow(
            hasActiveStripeSubscription: true,
            membershipEndsAt: '2026-04-01 01:09:19', // billing_period_ends_at already passed
        );

        $profile = PlayerProfile::fromDatabaseRow($row, $now);

        self::assertTrue($profile->activeMembership);
    }

    public function testActiveSubscriptionWithFutureBillingPeriod(): void
    {
        $now = new DateTimeImmutable('2026-03-15');

        $row = $this->createRow(
            hasActiveStripeSubscription: true,
            membershipEndsAt: '2026-04-01 01:09:19',
        );

        $profile = PlayerProfile::fromDatabaseRow($row, $now);

        self::assertTrue($profile->activeMembership);
    }

    public function testCancelledSubscriptionStillInGracePeriod(): void
    {
        $now = new DateTimeImmutable('2026-03-15');

        $row = $this->createRow(
            hasActiveStripeSubscription: false,
            membershipEndsAt: '2026-04-01 01:09:19', // ends_at is in the future
        );

        $profile = PlayerProfile::fromDatabaseRow($row, $now);

        self::assertTrue($profile->activeMembership);
    }

    public function testCancelledSubscriptionAfterGracePeriod(): void
    {
        $now = new DateTimeImmutable('2026-04-02');

        $row = $this->createRow(
            hasActiveStripeSubscription: false,
            membershipEndsAt: '2026-04-01 01:09:19', // ends_at is in the past
        );

        $profile = PlayerProfile::fromDatabaseRow($row, $now);

        self::assertFalse($profile->activeMembership);
    }

    public function testNoMembership(): void
    {
        $now = new DateTimeImmutable('2026-04-01');

        $row = $this->createRow(
            hasActiveStripeSubscription: false,
            membershipEndsAt: null,
        );

        $profile = PlayerProfile::fromDatabaseRow($row, $now);

        self::assertFalse($profile->activeMembership);
    }

    public function testGrantedMembershipStillActive(): void
    {
        $now = new DateTimeImmutable('2026-03-15');

        $row = $this->createRow(
            hasActiveStripeSubscription: false,
            membershipEndsAt: '2026-06-01', // granted_until in the future
        );

        $profile = PlayerProfile::fromDatabaseRow($row, $now);

        self::assertTrue($profile->activeMembership);
    }

    /**
     * @return array{
     *     player_id: string,
     *     user_id: null|string,
     *     player_name: null|string,
     *     email: null|string,
     *     country: null|string,
     *     city: null|string,
     *     code: string,
     *     favorite_players: string,
     *     avatar: null|string,
     *     bio: null|string,
     *     facebook: null|string,
     *     instagram: null|string,
     *     twitch: null|string,
     *     stripe_customer_id: null|string,
     *     modal_displayed: bool,
     *     locale: null|string,
     *     has_active_stripe_subscription: bool,
     *     membership_ends_at: null|string,
     *     is_admin: bool,
     *     is_private: bool,
     *     puzzle_collection_visibility: string,
     *     unsolved_puzzles_visibility: string,
     *     wish_list_visibility: string,
     *     lend_borrow_list_visibility: string,
     *     solved_puzzles_visibility: string,
     *     sell_swap_list_settings: null|string,
     *     allow_direct_messages: bool,
     *     email_notifications_enabled: bool,
     *     email_notification_frequency: string,
     *     newsletter_enabled: bool,
     *     rating_count: int|string,
     *     average_rating: null|string,
     *     streak_opted_out: bool,
     *     ranking_opted_out: bool,
     *     time_predictions_opted_out: bool,
     *     fair_use_policy_accepted_at: null|string,
     *     referral_program_joined_at: null|string,
     *     referral_program_suspended: bool,
     * }
     */
    private function createRow(
        bool $hasActiveStripeSubscription,
        null|string $membershipEndsAt,
    ): array {
        return [
            'player_id' => '018d0000-0000-0000-0000-000000000099',
            'user_id' => 'auth0|test',
            'player_name' => 'Test Player',
            'email' => 'test@example.com',
            'country' => null,
            'city' => null,
            'code' => 'testplayer',
            'favorite_players' => '[]',
            'avatar' => null,
            'bio' => null,
            'facebook' => null,
            'instagram' => null,
            'twitch' => null,
            'stripe_customer_id' => null,
            'modal_displayed' => false,
            'locale' => null,
            'has_active_stripe_subscription' => $hasActiveStripeSubscription,
            'membership_ends_at' => $membershipEndsAt,
            'is_admin' => false,
            'is_private' => false,
            'puzzle_collection_visibility' => CollectionVisibility::Public->value,
            'unsolved_puzzles_visibility' => CollectionVisibility::Public->value,
            'wish_list_visibility' => CollectionVisibility::Public->value,
            'lend_borrow_list_visibility' => CollectionVisibility::Public->value,
            'solved_puzzles_visibility' => CollectionVisibility::Public->value,
            'sell_swap_list_settings' => null,
            'allow_direct_messages' => true,
            'email_notifications_enabled' => true,
            'email_notification_frequency' => EmailNotificationFrequency::TwentyFourHours->value,
            'newsletter_enabled' => true,
            'rating_count' => 0,
            'average_rating' => null,
            'streak_opted_out' => false,
            'ranking_opted_out' => false,
            'time_predictions_opted_out' => false,
            'fair_use_policy_accepted_at' => null,
            'referral_program_joined_at' => null,
            'referral_program_suspended' => false,
        ];
    }
}
