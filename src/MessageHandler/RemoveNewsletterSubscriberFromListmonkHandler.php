<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\RemoveNewsletterSubscriberFromListmonk;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkClient;
use SpeedPuzzling\Web\Value\ListmonkSubscriber;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RemoveNewsletterSubscriberFromListmonkHandler
{
    public function __construct(
        private ListmonkClient $listmonkClient,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RemoveNewsletterSubscriberFromListmonk $message): void
    {
        if ($this->listmonkClient->isEnabled() === false) {
            return;
        }

        $email = mb_strtolower(trim($message->email));

        if ($email === '') {
            return;
        }

        $existingData = $this->listmonkClient->findSubscriberByEmail($email);
        $existing = $existingData === null ? null : ListmonkSubscriber::fromApi($existingData);

        if ($existing === null) {
            return;
        }

        // GDPR deletion: remove the subscriber entirely, campaign history included
        $this->listmonkClient->deleteSubscriber($existing->id);

        $this->logger->info('Deleted subscriber from Listmonk after player deletion', [
            'listmonk_subscriber_id' => $existing->id,
        ]);
    }
}
