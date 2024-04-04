<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\CollectUserFeedback;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\SentryApiClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class CollectUserFeedbackHandler
{
    public function __construct(
        private SentryApiClient $sentryApiClient,
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    public function __invoke(CollectUserFeedback $message): void
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        $name = $profile->playerName ?? $profile->playerId ?? 'Anonymous';
        $email = $profile->email ?? 'anonymous@speedpuzzling.cz';
        $comment = "URL: $message->url\nMessage: $message->message";

        $this->sentryApiClient->captureFeedback($name, $email, $comment);
    }
}
