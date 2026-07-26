<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Listmonk;

use SpeedPuzzling\Web\Value\DesiredNewsletterSubscriber;
use SpeedPuzzling\Web\Value\ListmonkSubscriber;
use SpeedPuzzling\Web\Value\NewsletterSyncDeletion;
use SpeedPuzzling\Web\Value\NewsletterSyncListUnsubscribe;
use SpeedPuzzling\Web\Value\NewsletterSyncPlan;
use SpeedPuzzling\Web\Value\NewsletterSyncUpdate;

/**
 * Pure diff between the MySpeedPuzzling desired state and the Listmonk actual
 * state. Direction rules:
 *
 * - An unsubscribe wins no matter where it happened: unsubscribed in Listmonk
 *   (one-click header, archive page) is pulled into MySpeedPuzzling; disabled
 *   in MySpeedPuzzling is pushed to Listmonk.
 * - The sync NEVER flips a Listmonk unsubscribe back to confirmed. Only an
 *   explicit user action (profile toggle, double opt-in confirm) does that,
 *   via PushNewsletterSubscriberToListmonk.
 * - Blocklisted subscribers (bounces) are left alone except for deletions.
 * - Subscribers in foreign (non-newsletter) lists are never fully deleted and
 *   their foreign memberships are preserved on updates.
 */
readonly final class NewsletterSyncPlanner
{
    /**
     * @param list<DesiredNewsletterSubscriber> $desired
     * @param list<ListmonkSubscriber> $actual
     * @param array<string, int> $listIdByLocale
     */
    public function plan(array $desired, array $actual, array $listIdByLocale): NewsletterSyncPlan
    {
        $newsletterListIds = array_values($listIdByLocale);

        $desiredByEmail = [];

        foreach ($desired as $desiredSubscriber) {
            $desiredByEmail[$desiredSubscriber->recipient->email] = $desiredSubscriber;
        }

        $pullUnsubscribes = [];
        $creates = [];
        $updates = [];
        $listUnsubscribes = [];
        $deletions = [];

        $actualEmails = [];

        foreach ($actual as $subscriber) {
            $actualEmails[$subscriber->email] = true;
            $desiredSubscriber = $desiredByEmail[$subscriber->email] ?? null;

            if ($desiredSubscriber === null) {
                $memberOfNewsletterLists = $subscriber->newsletterListIds($newsletterListIds);

                // Not in any newsletter list -> not managed by this sync
                if ($memberOfNewsletterLists === []) {
                    continue;
                }

                $foreignLists = $subscriber->foreignListIds($newsletterListIds);

                $deletions[] = new NewsletterSyncDeletion(
                    listmonkId: $subscriber->id,
                    fullDelete: $foreignLists === [],
                    removeFromListIds: $memberOfNewsletterLists,
                );

                continue;
            }

            if ($subscriber->isBlocklisted()) {
                continue;
            }

            $recipient = $desiredSubscriber->recipient;
            $targetListId = $this->targetListId($listIdByLocale, $recipient->locale);

            if ($recipient->subscribed) {
                if ($subscriber->isUnsubscribedFromAnyNewsletterList($newsletterListIds)) {
                    $pullUnsubscribes[] = $recipient;

                    continue;
                }

                $targetListIds = [...$subscriber->foreignListIds($newsletterListIds), $targetListId];
                sort($targetListIds);

                if ($this->needsUpdate($desiredSubscriber, $subscriber, $targetListId, $newsletterListIds)) {
                    $updates[] = new NewsletterSyncUpdate(
                        listmonkId: $subscriber->id,
                        desired: $desiredSubscriber,
                        targetListIds: $targetListIds,
                    );
                }

                continue;
            }

            // Desired unsubscribed: make sure no newsletter list still delivers
            $stillActiveListIds = [];

            foreach ($subscriber->newsletterListIds($newsletterListIds) as $listId) {
                if ($subscriber->listStatuses[$listId] !== 'unsubscribed') {
                    $stillActiveListIds[] = $listId;
                }
            }

            if ($stillActiveListIds !== []) {
                $listUnsubscribes[] = new NewsletterSyncListUnsubscribe(
                    listmonkId: $subscriber->id,
                    listIds: $stillActiveListIds,
                );
            }
        }

        foreach ($desiredByEmail as $email => $desiredSubscriber) {
            if (isset($actualEmails[$email])) {
                continue;
            }

            // Never-synced unsubscribed recipients do not need a suppression row
            if ($desiredSubscriber->recipient->subscribed) {
                $creates[] = $desiredSubscriber;
            }
        }

        return new NewsletterSyncPlan(
            pullUnsubscribes: $pullUnsubscribes,
            creates: $creates,
            updates: $updates,
            listUnsubscribes: $listUnsubscribes,
            deletions: $deletions,
        );
    }

    /**
     * @param array<string, int> $listIdByLocale
     */
    public function targetListId(array $listIdByLocale, null|string $locale): int
    {
        $normalized = ListmonkNewsletterLists::normalizeLocale($locale);

        return $listIdByLocale[$normalized] ?? $listIdByLocale[ListmonkNewsletterLists::DEFAULT_LOCALE] ?? 0;
    }

    /**
     * @param list<int> $newsletterListIds
     */
    private function needsUpdate(
        DesiredNewsletterSubscriber $desired,
        ListmonkSubscriber $subscriber,
        int $targetListId,
        array $newsletterListIds,
    ): bool {
        if ($subscriber->status !== 'enabled') {
            return true;
        }

        if ($subscriber->name !== $desired->recipient->name) {
            return true;
        }

        $currentNewsletterLists = $subscriber->newsletterListIds($newsletterListIds);
        sort($currentNewsletterLists);

        if ($currentNewsletterLists !== [$targetListId]) {
            return true;
        }

        if (($subscriber->listStatuses[$targetListId] ?? null) !== 'confirmed') {
            return true;
        }

        foreach ($desired->attributes as $key => $value) {
            $actualValue = $subscriber->attribs[$key] ?? null;

            if (!is_scalar($actualValue) || (string) $actualValue !== $value) {
                return true;
            }
        }

        return false;
    }
}
