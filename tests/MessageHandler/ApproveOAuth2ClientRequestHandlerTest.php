<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use SpeedPuzzling\Web\Message\ApproveOAuth2ClientRequest;
use SpeedPuzzling\Web\Repository\OAuth2ClientRequestRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientRequestFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\OAuth2ClientRequestStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ApproveOAuth2ClientRequestHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private OAuth2ClientRequestRepository $requestRepository;
    private ClientManagerInterface $clientManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->requestRepository = $container->get(OAuth2ClientRequestRepository::class);
        $this->clientManager = $container->get(ClientManagerInterface::class);
    }

    public function testApprovedConfidentialClientHasClientCredentialsGrant(): void
    {
        $this->messageBus->dispatch(
            new ApproveOAuth2ClientRequest(
                requestId: OAuth2ClientRequestFixture::PENDING_CONFIDENTIAL_REQUEST,
                adminPlayerId: PlayerFixture::PLAYER_ADMIN,
            ),
        );

        $request = $this->requestRepository->get(OAuth2ClientRequestFixture::PENDING_CONFIDENTIAL_REQUEST);

        self::assertSame(OAuth2ClientRequestStatus::Approved, $request->status);
        self::assertNotNull($request->clientIdentifier);

        $client = $this->clientManager->find($request->clientIdentifier);
        self::assertNotNull($client);

        $grantTypes = array_map(
            static fn(Grant $grant) => (string) $grant,
            $client->getGrants(),
        );

        self::assertContains('authorization_code', $grantTypes);
        self::assertContains('client_credentials', $grantTypes);
        self::assertContains('refresh_token', $grantTypes);
    }

    public function testApprovedPublicClientHasNoSecret(): void
    {
        $this->messageBus->dispatch(
            new ApproveOAuth2ClientRequest(
                requestId: OAuth2ClientRequestFixture::PENDING_PUBLIC_REQUEST,
                adminPlayerId: PlayerFixture::PLAYER_ADMIN,
            ),
        );

        $request = $this->requestRepository->get(OAuth2ClientRequestFixture::PENDING_PUBLIC_REQUEST);

        self::assertSame(OAuth2ClientRequestStatus::Approved, $request->status);
        self::assertNull($request->clientSecret);
    }

    public function testApprovedClientHasRequestedScopes(): void
    {
        $this->messageBus->dispatch(
            new ApproveOAuth2ClientRequest(
                requestId: OAuth2ClientRequestFixture::PENDING_CONFIDENTIAL_REQUEST,
                adminPlayerId: PlayerFixture::PLAYER_ADMIN,
            ),
        );

        $request = $this->requestRepository->get(OAuth2ClientRequestFixture::PENDING_CONFIDENTIAL_REQUEST);
        self::assertNotNull($request->clientIdentifier);

        $client = $this->clientManager->find($request->clientIdentifier);
        self::assertNotNull($client);

        $scopes = array_map(
            static fn($scope) => (string) $scope,
            $client->getScopes(),
        );

        self::assertContains('profile:read', $scopes);
        self::assertContains('results:read', $scopes);
        self::assertContains('statistics:read', $scopes);
    }
}
