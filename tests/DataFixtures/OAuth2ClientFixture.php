<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;

final class OAuth2ClientFixture extends Fixture
{
    public const string PUBLIC_CLIENT_ID = 'public-test-client';
    public const string CONFIDENTIAL_CLIENT_ID = 'confidential-test-client';
    public const string CONFIDENTIAL_CLIENT_SECRET = 'test-secret-12345678901234567890123456789012';
    public const string FIRST_PARTY_CLIENT_ID = 'first-party-client';
    public const string REDIRECT_URI = 'https://example.com/callback';

    public function __construct(
        private readonly ClientManagerInterface $clientManager,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Public client (for mobile apps, SPAs - PKCE required)
        $publicClient = new Client(
            'Public Test Client',
            self::PUBLIC_CLIENT_ID,
            null, // No secret for public client
        );
        $publicClient->setRedirectUris(new RedirectUri(self::REDIRECT_URI));
        $publicClient->setGrants(
            new Grant('authorization_code'),
            new Grant('refresh_token'),
        );
        $publicClient->setScopes(
            new Scope('profile:read'),
            new Scope('results:read'),
            new Scope('statistics:read'),
        );
        $this->clientManager->save($publicClient);
        $this->addReference(self::PUBLIC_CLIENT_ID, $publicClient);

        // Confidential client (for server-side apps)
        $confidentialClient = new Client(
            'Confidential Test Client',
            self::CONFIDENTIAL_CLIENT_ID,
            self::CONFIDENTIAL_CLIENT_SECRET,
        );
        $confidentialClient->setRedirectUris(new RedirectUri(self::REDIRECT_URI));
        $confidentialClient->setGrants(
            new Grant('authorization_code'),
            new Grant('client_credentials'),
            new Grant('refresh_token'),
        );
        $confidentialClient->setScopes(
            new Scope('profile:read'),
            new Scope('results:read'),
            new Scope('statistics:read'),
        );
        $this->clientManager->save($confidentialClient);
        $this->addReference(self::CONFIDENTIAL_CLIENT_ID, $confidentialClient);

        // First-party client (auto-approve consent)
        $firstPartyClient = new Client(
            'First Party Client',
            self::FIRST_PARTY_CLIENT_ID,
            'first-party-secret-12345678901234567890',
        );
        $firstPartyClient->setRedirectUris(new RedirectUri(self::REDIRECT_URI));
        $firstPartyClient->setGrants(
            new Grant('authorization_code'),
            new Grant('refresh_token'),
        );
        $firstPartyClient->setScopes(
            new Scope('profile:read'),
            new Scope('results:read'),
            new Scope('statistics:read'),
            new Scope('collections:read'),
        );
        $this->clientManager->save($firstPartyClient);
        $this->addReference(self::FIRST_PARTY_CLIENT_ID, $firstPartyClient);
    }
}
