<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

readonly final class TestingLogin
{
    /**
     * Logs the player in with a UserAccount session. The account row is created on
     * first use because the next request's session refresh resolves the user from
     * the database through the user provider.
     */
    public static function asPlayer(KernelBrowser $browser, string $playerId, string $firewall = 'main'): void
    {
        $container = $browser->getContainer();

        $repository = $container->get(PlayerRepository::class);
        $player = $repository->get($playerId);
        assert($player->userId !== null);

        $userAccount = $container->get(UserAccountRepository::class)->findByUserId($player->userId);

        if ($userAccount === null) {
            $userAccount = new UserAccount(
                Uuid::uuid7(),
                $player->userId,
                $player->code . '@test.local',
                new DateTimeImmutable(),
            );

            if (str_starts_with($player->userId, 'auth0|')) {
                // auth0|... fixture players stand for accounts imported from Auth0 -
                // mirror the state the import left behind (legacy flag + verified email)
                $userAccount->applyAuth0Import(
                    $userAccount->email,
                    null,
                    true,
                    new DateTimeImmutable(),
                );
            }

            // The public 'doctrine' registry: this helper is not a TestCase, so the
            // private EntityManagerInterface service is off limits to it
            $entityManager = $container->get('doctrine')->getManager();
            $entityManager->persist($userAccount);
            $entityManager->flush();
        }

        $browser->loginUser($userAccount, $firewall);
    }
}
