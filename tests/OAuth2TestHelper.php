<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use League\Bundle\OAuth2ServerBundle\Manager\AccessTokenManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AccessToken;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;

final readonly class OAuth2TestHelper
{
    /**
     * @param array<non-empty-string> $scopes
     */
    public static function createAccessToken(
        KernelBrowser $browser,
        string $clientId,
        null|string $userIdentifier = null,
        array $scopes = ['profile:read'],
    ): string {
        /** @var ContainerInterface $container */
        $container = $browser->getContainer();

        /** @var ClientManagerInterface $clientManager */
        $clientManager = $container->get('league.oauth2_server.manager.doctrine.client'); // @phpstan-ignore symfonyContainer.privateService

        /** @var AccessTokenManagerInterface $accessTokenManager */
        $accessTokenManager = $container->get('league.oauth2_server.manager.doctrine.access_token'); // @phpstan-ignore symfonyContainer.privateService

        $client = $clientManager->find($clientId);

        if ($client === null) {
            throw new \RuntimeException(sprintf('Client "%s" not found', $clientId));
        }

        /** @var non-empty-string $identifier */
        $identifier = Uuid::uuid7()->toString();
        $expiry = new DateTimeImmutable('+1 hour');

        /** @var list<Scope> $scopeObjects */
        $scopeObjects = array_values(array_map(
            static fn(string $scope): Scope => new Scope($scope),
            $scopes,
        ));

        $accessToken = new AccessToken(
            identifier: $identifier,
            expiry: $expiry,
            client: $client,
            userIdentifier: $userIdentifier,
            scopes: $scopeObjects,
        );

        $accessTokenManager->save($accessToken);

        return self::generateJwt($container, $identifier, $expiry, $clientId, $userIdentifier, $scopes);
    }

    /**
     * @param array<non-empty-string> $scopes
     */
    private static function generateJwt(
        ContainerInterface $container,
        string $identifier,
        DateTimeImmutable $expiry,
        string $clientId,
        null|string $userIdentifier,
        array $scopes,
    ): string {
        /** @var string $projectDir */
        $projectDir = $container->getParameter('kernel.project_dir');
        $privateKeyPath = $projectDir . '/config/jwt/private.pem';

        $configuration = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::file($privateKeyPath),
            InMemory::file($privateKeyPath), // Public key not needed for signing
        );

        $now = new DateTimeImmutable();

        /** @var non-empty-string $subject */
        $subject = $userIdentifier !== null && $userIdentifier !== '' ? $userIdentifier : 'anonymous';

        $builder = $configuration->builder()
            ->identifiedBy($identifier !== '' ? $identifier : 'default')
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($expiry)
            ->relatedTo($subject)
            ->withClaim('client_id', $clientId)
            ->withClaim('scopes', $scopes);

        return $builder->getToken($configuration->signer(), $configuration->signingKey())->toString();
    }

    public static function addBearerToken(KernelBrowser $browser, string $token): void
    {
        $browser->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
    }
}
