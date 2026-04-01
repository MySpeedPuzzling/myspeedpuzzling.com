<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PersonalAccessToken;
use SpeedPuzzling\Web\Entity\Player;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;

final readonly class PatTestHelper
{
    public static function createToken(KernelBrowser $browser, string $playerId): string
    {
        /** @var ContainerInterface $container */
        $container = $browser->getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get('doctrine.orm.entity_manager');

        $player = $entityManager->find(Player::class, $playerId);
        assert($player !== null);

        $plainToken = 'msp_pat_' . bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $plainToken);
        $tokenPrefix = substr($plainToken, 0, 16);
        $now = new DateTimeImmutable();

        $pat = new PersonalAccessToken(
            id: Uuid::uuid7(),
            player: $player,
            name: 'Test Token',
            tokenHash: $tokenHash,
            tokenPrefix: $tokenPrefix,
            fairUsePolicyAcceptedAt: $now,
            createdAt: $now,
        );

        $entityManager->persist($pat);
        $entityManager->flush();

        return $plainToken;
    }

    public static function addBearerToken(KernelBrowser $browser, string $token): void
    {
        $browser->setServerParameter('HTTP_AUTHORIZATION', 'Token ' . $token);
    }
}
