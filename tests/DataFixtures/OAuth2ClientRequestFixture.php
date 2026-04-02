<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OAuth2\OAuth2ClientRequest;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Value\OAuth2ApplicationType;

final class OAuth2ClientRequestFixture extends Fixture implements DependentFixtureInterface
{
    public const string PENDING_CONFIDENTIAL_REQUEST = '018d0010-0000-0000-0000-000000000001';
    public const string PENDING_PUBLIC_REQUEST = '018d0010-0000-0000-0000-000000000002';

    public function load(ObjectManager $manager): void
    {
        $player = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);

        $confidentialRequest = new OAuth2ClientRequest(
            id: Uuid::fromString(self::PENDING_CONFIDENTIAL_REQUEST),
            player: $player,
            clientName: 'Test Confidential App',
            clientDescription: 'A test confidential application',
            purpose: 'Testing OAuth2 approval flow',
            applicationType: OAuth2ApplicationType::Confidential,
            requestedScopes: ['profile:read', 'results:read', 'statistics:read'],
            redirectUris: ['https://example.com/callback'],
            fairUsePolicyAcceptedAt: new DateTimeImmutable('2025-01-01 10:00:00'),
            createdAt: new DateTimeImmutable('2025-01-01 10:00:00'),
        );
        $manager->persist($confidentialRequest);

        $publicRequest = new OAuth2ClientRequest(
            id: Uuid::fromString(self::PENDING_PUBLIC_REQUEST),
            player: $player,
            clientName: 'Test Public App',
            clientDescription: 'A test public application',
            purpose: 'Testing OAuth2 approval flow for public clients',
            applicationType: OAuth2ApplicationType::Public,
            requestedScopes: ['profile:read', 'results:read'],
            redirectUris: ['https://example.com/callback'],
            fairUsePolicyAcceptedAt: new DateTimeImmutable('2025-01-01 10:00:00'),
            createdAt: new DateTimeImmutable('2025-01-01 10:00:00'),
        );
        $manager->persist($publicRequest);

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
        ];
    }
}
