<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\PushNewsletterSubscriberToListmonk;
use SpeedPuzzling\Web\Query\GetNewsletterRecipients;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkClient;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use SpeedPuzzling\Web\Services\Listmonk\NewsletterAttributesBuilder;
use SpeedPuzzling\Web\Value\ListmonkSubscriber;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Applies the current MySpeedPuzzling subscription state of one address to
 * Listmonk immediately after an explicit user action. This is the only place
 * allowed to flip a Listmonk unsubscribe back to confirmed (the cron sync
 * never does).
 */
#[AsMessageHandler]
readonly final class PushNewsletterSubscriberToListmonkHandler
{
    public function __construct(
        private ListmonkClient $listmonkClient,
        private ListmonkNewsletterLists $newsletterLists,
        private NewsletterAttributesBuilder $attributesBuilder,
        private GetNewsletterRecipients $getNewsletterRecipients,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PushNewsletterSubscriberToListmonk $message): void
    {
        if ($this->listmonkClient->isEnabled() === false) {
            return;
        }

        $email = mb_strtolower(trim($message->email));

        if ($email === '') {
            return;
        }

        $recipient = $this->getNewsletterRecipients->byEmail($email);

        $existingData = $this->listmonkClient->findSubscriberByEmail($email);
        $existing = $existingData === null ? null : ListmonkSubscriber::fromApi($existingData);

        $listIdByLocale = $this->newsletterLists->ensureListsExist();
        $newsletterListIds = array_values($listIdByLocale);

        if ($recipient === null) {
            // Nothing on our side (pending signup or vanished row): only clean up
            // newsletter-list memberships, never touch foreign lists
            if ($existing !== null && $existing->newsletterListIds($newsletterListIds) !== []) {
                if ($existing->foreignListIds($newsletterListIds) === []) {
                    $this->listmonkClient->deleteSubscriber($existing->id);
                } else {
                    $this->listmonkClient->removeFromLists([$existing->id], $existing->newsletterListIds($newsletterListIds));
                }
            }

            return;
        }

        if ($existing !== null && $existing->isBlocklisted()) {
            $this->logger->info('Skipping Listmonk push for blocklisted subscriber', ['email_hash' => hash('sha256', $email)]);

            return;
        }

        if ($recipient->subscribed) {
            $locale = ListmonkNewsletterLists::normalizeLocale($recipient->locale);
            $targetListId = $listIdByLocale[$locale] ?? $listIdByLocale[ListmonkNewsletterLists::DEFAULT_LOCALE];
            $attributes = $this->attributesBuilder->build($recipient);

            if ($existing === null) {
                $this->listmonkClient->createSubscriber($email, $recipient->name, [$targetListId], $attributes);

                return;
            }

            $targetListIds = [...$existing->foreignListIds($newsletterListIds), $targetListId];

            $this->listmonkClient->updateSubscriber($existing->id, $email, $recipient->name, $targetListIds, $attributes);
            // Explicit re-subscribe: force the membership back to confirmed even
            // when it was previously unsubscribed
            $this->listmonkClient->confirmListSubscriptions([$existing->id], [$targetListId]);

            return;
        }

        if ($existing !== null) {
            $this->listmonkClient->unsubscribeFromLists([$existing->id], $newsletterListIds);
        }
    }
}
