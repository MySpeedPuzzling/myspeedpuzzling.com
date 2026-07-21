<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\PasswordChangeRequestFailed;
use SpeedPuzzling\Web\Message\RequestPasswordChange;
use SpeedPuzzling\Web\Services\Auth0DatabaseConnection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
readonly final class RequestPasswordChangeHandler
{
    public function __construct(
        private HttpClientInterface $client,
        private LoggerInterface $logger,
        private string $auth0Domain,
        private string $auth0ClientId,
        private string $auth0DatabaseConnection,
    ) {
    }

    /**
     * @throws PasswordChangeRequestFailed
     */
    public function __invoke(RequestPasswordChange $message): void
    {
        // Defense in depth - the controller already gates on this, but a social
        // identity must never reach Auth0 with a database connection name.
        if (Auth0DatabaseConnection::hasPassword($message->userId) === false) {
            throw new PasswordChangeRequestFailed('Password change is only available for database connection users');
        }

        $url = sprintf('https://%s/dbconnections/change_password', $this->auth0Domain);

        try {
            $response = $this->client->request('POST', $url, [
                'json' => [
                    'client_id' => $this->auth0ClientId,
                    'email' => $message->email,
                    'connection' => $this->auth0DatabaseConnection,
                ],
            ]);

            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Could not reach Auth0 to request password change', [
                'exception' => $exception,
                'user_id' => $message->userId,
            ]);

            throw new PasswordChangeRequestFailed('Could not reach Auth0', previous: $exception);
        }

        if ($statusCode !== 200) {
            // Auth0 answers 200 with a generic message even for unknown emails,
            // so anything else is a real failure (429 rate limit, misconfigured
            // connection name, client not allowed to use the connection, ...).
            $this->logger->error('Auth0 rejected the password change request', [
                'user_id' => $message->userId,
                'status_code' => $statusCode,
                'connection' => $this->auth0DatabaseConnection,
            ]);

            throw new PasswordChangeRequestFailed(
                sprintf('Auth0 responded with status code %d', $statusCode),
            );
        }
    }
}
