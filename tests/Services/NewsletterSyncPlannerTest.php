<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\NewsletterRecipient;
use SpeedPuzzling\Web\Services\Listmonk\NewsletterSyncPlanner;
use SpeedPuzzling\Web\Value\DesiredNewsletterSubscriber;
use SpeedPuzzling\Web\Value\ListmonkSubscriber;
use SpeedPuzzling\Web\Value\NewsletterAudience;

final class NewsletterSyncPlannerTest extends TestCase
{
    private const array LIST_IDS = ['en' => 1, 'cs' => 2, 'de' => 3, 'es' => 4, 'fr' => 5, 'ja' => 6];
    private const int FOREIGN_LIST_ID = 99;

    private NewsletterSyncPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new NewsletterSyncPlanner();
    }

    public function testMissingSubscribedRecipientIsCreated(): void
    {
        $plan = $this->planner->plan([self::desired('new@example.com', subscribed: true, locale: 'cs')], [], self::LIST_IDS);

        self::assertCount(1, $plan->creates);
        self::assertSame('new@example.com', $plan->creates[0]->recipient->email);
        self::assertSame([], $plan->updates);
    }

    public function testMissingUnsubscribedRecipientIsNotCreated(): void
    {
        $plan = $this->planner->plan([self::desired('gone@example.com', subscribed: false)], [], self::LIST_IDS);

        self::assertTrue($plan->isEmpty());
    }

    public function testConsistentSubscriberProducesNoChanges(): void
    {
        $desired = self::desired('same@example.com', subscribed: true, locale: 'cs');

        $actual = self::actual(10, 'same@example.com', [2 => 'confirmed'], attribs: $desired->attributes);

        $plan = $this->planner->plan([$desired], [$actual], self::LIST_IDS);

        self::assertTrue($plan->isEmpty());
    }

    public function testListmonkUnsubscribeIsPulled(): void
    {
        $desired = self::desired('left@example.com', subscribed: true, locale: 'cs');
        $actual = self::actual(11, 'left@example.com', [2 => 'unsubscribed'], attribs: $desired->attributes);

        $plan = $this->planner->plan([$desired], [$actual], self::LIST_IDS);

        self::assertCount(1, $plan->pullUnsubscribes);
        self::assertSame('left@example.com', $plan->pullUnsubscribes[0]->email);
        // Never also updated in the same run - the pull wins
        self::assertSame([], $plan->updates);
    }

    public function testMspUnsubscribeIsPushedToListmonk(): void
    {
        $desired = self::desired('optout@example.com', subscribed: false, locale: 'cs');
        $actual = self::actual(12, 'optout@example.com', [2 => 'confirmed']);

        $plan = $this->planner->plan([$desired], [$actual], self::LIST_IDS);

        self::assertCount(1, $plan->listUnsubscribes);
        self::assertSame(12, $plan->listUnsubscribes[0]->listmonkId);
        self::assertSame([2], $plan->listUnsubscribes[0]->listIds);
    }

    public function testAlreadyUnsubscribedEverywhereIsLeftAlone(): void
    {
        $desired = self::desired('quiet@example.com', subscribed: false, locale: 'cs');
        $actual = self::actual(13, 'quiet@example.com', [2 => 'unsubscribed']);

        $plan = $this->planner->plan([$desired], [$actual], self::LIST_IDS);

        self::assertTrue($plan->isEmpty());
    }

    public function testOrphanInNewsletterListOnlyIsFullyDeleted(): void
    {
        $actual = self::actual(14, 'deleted-player@example.com', [1 => 'confirmed']);

        $plan = $this->planner->plan([], [$actual], self::LIST_IDS);

        self::assertCount(1, $plan->deletions);
        self::assertTrue($plan->deletions[0]->fullDelete);
    }

    public function testOrphanWithForeignListOnlyLosesNewsletterMembership(): void
    {
        $actual = self::actual(15, 'foreign@example.com', [1 => 'confirmed', self::FOREIGN_LIST_ID => 'confirmed']);

        $plan = $this->planner->plan([], [$actual], self::LIST_IDS);

        self::assertCount(1, $plan->deletions);
        self::assertFalse($plan->deletions[0]->fullDelete);
        self::assertSame([1], $plan->deletions[0]->removeFromListIds);
    }

    public function testSubscriberOutsideNewsletterListsIsNotManaged(): void
    {
        $actual = self::actual(16, 'unrelated@example.com', [self::FOREIGN_LIST_ID => 'confirmed']);

        $plan = $this->planner->plan([], [$actual], self::LIST_IDS);

        self::assertTrue($plan->isEmpty());
    }

    public function testBlocklistedSubscriberIsNeverTouched(): void
    {
        $desired = self::desired('bounced@example.com', subscribed: true, locale: 'cs');
        $actual = self::actual(17, 'bounced@example.com', [2 => 'unsubscribed'], status: 'blocklisted');

        $plan = $this->planner->plan([$desired], [$actual], self::LIST_IDS);

        self::assertTrue($plan->isEmpty());
    }

    public function testLocaleChangeMovesSubscriberBetweenLists(): void
    {
        $desired = self::desired('mover@example.com', subscribed: true, locale: 'en');
        $actual = self::actual(18, 'mover@example.com', [2 => 'confirmed'], attribs: $desired->attributes);

        $plan = $this->planner->plan([$desired], [$actual], self::LIST_IDS);

        self::assertCount(1, $plan->updates);
        self::assertSame([1], $plan->updates[0]->targetListIds);
    }

    public function testForeignListsArePreservedOnUpdate(): void
    {
        $desired = self::desired('both@example.com', subscribed: true, locale: 'cs');
        $actual = self::actual(19, 'both@example.com', [1 => 'confirmed', self::FOREIGN_LIST_ID => 'confirmed'], attribs: $desired->attributes);

        $plan = $this->planner->plan([$desired], [$actual], self::LIST_IDS);

        self::assertCount(1, $plan->updates);
        self::assertSame([2, self::FOREIGN_LIST_ID], $plan->updates[0]->targetListIds);
    }

    public function testAttributeDriftTriggersUpdate(): void
    {
        $desired = self::desired('drift@example.com', subscribed: true, locale: 'cs');
        $staleAttribs = $desired->attributes;
        $staleAttribs['unsubscribe_url'] = 'https://old.example.com/unsubscribe';

        $actual = self::actual(20, 'drift@example.com', [2 => 'confirmed'], attribs: $staleAttribs);

        $plan = $this->planner->plan([$desired], [$actual], self::LIST_IDS);

        self::assertCount(1, $plan->updates);
    }

    public function testUnconfirmedTargetMembershipTriggersUpdate(): void
    {
        $desired = self::desired('unconfirmed@example.com', subscribed: true, locale: 'cs');
        $actual = self::actual(21, 'unconfirmed@example.com', [2 => 'unconfirmed'], attribs: $desired->attributes);

        $plan = $this->planner->plan([$desired], [$actual], self::LIST_IDS);

        self::assertCount(1, $plan->updates);
    }

    private static function desired(string $email, bool $subscribed, string $locale = 'en', string $name = 'Test'): DesiredNewsletterSubscriber
    {
        $recipient = new NewsletterRecipient(
            audience: NewsletterAudience::Player,
            id: '018d0000-0000-0000-0000-00000000abcd',
            email: $email,
            name: $name,
            locale: $locale,
            subscribed: $subscribed,
        );

        return new DesiredNewsletterSubscriber($recipient, [
            'locale' => $locale,
            'audience' => 'player',
            'unsubscribe_url' => 'https://example.com/unsubscribe/' . $email,
        ]);
    }

    /**
     * @param array<int, string> $listStatuses
     * @param array<string, mixed> $attribs
     */
    private static function actual(int $id, string $email, array $listStatuses, string $status = 'enabled', array $attribs = [], string $name = 'Test'): ListmonkSubscriber
    {
        return new ListmonkSubscriber(
            id: $id,
            email: $email,
            name: $name,
            status: $status,
            listStatuses: $listStatuses,
            attribs: $attribs,
        );
    }
}
