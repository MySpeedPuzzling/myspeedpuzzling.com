<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use SpeedPuzzling\Web\Message\ApproveOAuth2ClientRequest;
use SpeedPuzzling\Web\Message\ResetOAuth2ClientCredentials;
use SpeedPuzzling\Web\Repository\OAuth2ClientRequestRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientRequestFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ResetOAuth2ClientCredentialsHandlerTest extends KernelTestCase
{
    public function testResetStoresTheHashOfTheNewSecretOnTheSameClient(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $messageBus = $container->get(MessageBusInterface::class);
        $requestRepository = $container->get(OAuth2ClientRequestRepository::class);
        $clientManager = $container->get(ClientManagerInterface::class);
        $hasher = $container->get('league.oauth2_server.password_hasher');

        $messageBus->dispatch(new ApproveOAuth2ClientRequest(
            requestId: OAuth2ClientRequestFixture::PENDING_CONFIDENTIAL_REQUEST,
            adminPlayerId: PlayerFixture::PLAYER_ADMIN,
        ));

        $request = $requestRepository->get(OAuth2ClientRequestFixture::PENDING_CONFIDENTIAL_REQUEST);
        $clientIdentifier = $request->clientIdentifier;
        $oldSecret = $request->clientSecret;
        self::assertNotNull($clientIdentifier);
        self::assertNotNull($oldSecret);

        $messageBus->dispatch(new ResetOAuth2ClientCredentials(
            requestId: OAuth2ClientRequestFixture::PENDING_CONFIDENTIAL_REQUEST,
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));

        $request = $requestRepository->get(OAuth2ClientRequestFixture::PENDING_CONFIDENTIAL_REQUEST);
        $newSecret = $request->clientSecret;
        self::assertNotNull($newSecret);
        self::assertNotSame($oldSecret, $newSecret);
        self::assertFalse($request->credentialsClaimed);

        $client = $clientManager->find($clientIdentifier);
        self::assertNotNull($client);
        $storedSecret = $client->getSecret();
        self::assertNotNull($storedSecret);
        self::assertNotSame($newSecret, $storedSecret);
        self::assertTrue($hasher->verify($storedSecret, $newSecret));
        self::assertFalse($hasher->verify($storedSecret, $oldSecret));
    }
}
