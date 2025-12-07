<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

readonly final class TestingLogin
{
    public static function asPlayer(KernelBrowser $browser, string $playerId): void
    {
        $container = $browser->getContainer();

        $repository = $container->get(PlayerRepository::class);
        $player = $repository->get($playerId);

        $auth0User = new User([
            'user_id' => $player->userId,
            'sub' => $player->userId,
            'email' => $player->email,
            'name' => $player->name,
            'email_verified' => true,
        ]);

        $browser->loginUser($auth0User);
    }
}
