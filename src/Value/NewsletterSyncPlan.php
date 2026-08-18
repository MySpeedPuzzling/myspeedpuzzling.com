<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Results\NewsletterRecipient;

readonly final class NewsletterSyncPlan
{
    public function __construct(
        /** @var list<NewsletterRecipient> unsubscribed in Listmonk -> propagate to MySpeedPuzzling */
        public array $pullUnsubscribes,
        /** @var list<DesiredNewsletterSubscriber> missing in Listmonk -> create */
        public array $creates,
        /** @var list<NewsletterSyncUpdate> drifted in Listmonk -> update */
        public array $updates,
        /** @var list<NewsletterSyncListUnsubscribe> unsubscribed in MySpeedPuzzling -> mark unsubscribed in Listmonk */
        public array $listUnsubscribes,
        /** @var list<NewsletterSyncDeletion> gone from MySpeedPuzzling -> remove from Listmonk */
        public array $deletions,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->pullUnsubscribes === []
            && $this->creates === []
            && $this->updates === []
            && $this->listUnsubscribes === []
            && $this->deletions === [];
    }
}
