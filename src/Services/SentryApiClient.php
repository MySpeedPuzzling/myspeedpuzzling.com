<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Sentry\EventId;
use Sentry\SentrySdk;
use Symfony\Contracts\HttpClient\HttpClientInterface;

readonly final class SentryApiClient
{
    public function __construct(
        private string $authToken,
        private string $organizationSlug,
        private string $projectSlug,
        private HttpClientInterface $client,
    ) {
    }

    public function captureFeedback(string $name, string $email, string $comment): void
    {
        $url = sprintf('https://sentry.io/api/0/projects/%s/%s/user-feedback/',
            $this->organizationSlug,
            $this->projectSlug,
        );

        $this->client->request('POST', $url, [
            'auth_bearer' => $this->authToken,
            'json' => [
                'event_id' => (string) (SentrySdk::getCurrentHub()->getLastEventId() ?? EventId::generate()),
                'name' => $name,
                'email' => $email,
                'comments' => $comment,
            ],
        ]);
    }
}
