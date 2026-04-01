<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use SpeedPuzzling\Web\Message\ResetOAuth2ClientCredentials;
use SpeedPuzzling\Web\Repository\OAuth2ClientRequestRepository;
use SpeedPuzzling\Web\Value\OAuth2ApplicationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ResetOAuth2ClientCredentialsHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OAuth2ClientRequestRepository $requestRepository,
        private ClientManagerInterface $clientManager,
        private Connection $database,
    ) {
    }

    public function __invoke(ResetOAuth2ClientCredentials $message): string
    {
        $request = $this->requestRepository->get($message->requestId);

        assert($request->player->id->toString() === $message->playerId);
        assert($request->clientIdentifier !== null);

        $client = $this->clientManager->find($request->clientIdentifier);
        assert($client instanceof Client);

        $newSecret = null;

        if ($request->applicationType === OAuth2ApplicationType::Confidential) {
            $newSecret = bin2hex(random_bytes(32));

            $newClient = new Client($client->getName(), $client->getIdentifier(), $newSecret);
            $newClient->setRedirectUris(...$client->getRedirectUris());
            $newClient->setGrants(...$client->getGrants());
            $newClient->setScopes(...$client->getScopes());
            $this->clientManager->save($newClient);
        }

        $claimToken = bin2hex(random_bytes(32));
        $request->resetCredentials($newSecret ?? '', $claimToken);

        // Revoke all existing tokens for this client via raw SQL
        $this->database->executeStatement(
            'UPDATE oauth2_access_token SET revoked = true WHERE client = :client',
            ['client' => $request->clientIdentifier],
        );

        $this->entityManager->flush();

        return $claimToken;
    }
}
