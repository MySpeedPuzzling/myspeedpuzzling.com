<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Kernel tests have no signed-in viewer, so read-side queries hide nobody (see
 * docs/features/player-blocklist.md). This puts a fixture player's token in place, the way a web
 * request would, for tests of what a *particular* viewer is shown.
 */
readonly final class TestingViewer
{
    public static function signIn(ContainerInterface $container, string $playerId): void
    {
        $player = $container->get(PlayerRepository::class)->get($playerId);
        assert($player->userId !== null);

        $userAccount = $container->get(UserAccountRepository::class)->findByUserId($player->userId);

        if ($userAccount === null) {
            $userAccount = new UserAccount(
                Uuid::uuid7(),
                $player->userId,
                $player->email ?? $player->code . '@test.local',
                new DateTimeImmutable(),
            );

            $entityManager = $container->get('doctrine')->getManager();
            $entityManager->persist($userAccount);
            $entityManager->flush();
        }

        // @phpstan-ignore symfonyContainer.privateService (the test container exposes it)
        $container->get(TokenStorageInterface::class)->setToken(
            new PostAuthenticationToken($userAccount, 'main', $userAccount->getRoles()),
        );
        $container->get(RetrieveLoggedUserProfile::class)->reset();
        $container->get(HiddenPlayers::class)->reset();
    }

    public static function signOut(ContainerInterface $container): void
    {
        // @phpstan-ignore symfonyContainer.privateService (the test container exposes it)
        $container->get(TokenStorageInterface::class)->setToken(null);
        $container->get(RetrieveLoggedUserProfile::class)->reset();
        $container->get(HiddenPlayers::class)->reset();
    }
}
