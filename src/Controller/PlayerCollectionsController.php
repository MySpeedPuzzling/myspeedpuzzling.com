<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class PlayerCollectionsController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetPlayerCollections $getPlayerCollections,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/profil-hrace/{playerId}/kolekce',
            'en' => '/en/player-profile/{playerId}/collections',
        ],
        name: 'player_collections',
    )]
    public function __invoke(string $playerId, #[CurrentUser] null|UserInterface $user): Response
    {
        try {
            $player = $this->getPlayerProfile->byId($playerId);
        } catch (PlayerNotFound) {
            throw $this->createNotFoundException();
        }

        $loggedUserProfile = null;
        if ($user !== null) {
            $loggedUserProfile = $this->retrieveLoggedUserProfile->getProfile($user);
        }

        $isOwnProfile = $loggedUserProfile !== null && $loggedUserProfile->playerId === $playerId;

        if ($isOwnProfile) {
            // Show all collections for own profile
            $collections = $this->getPlayerCollections->allByPlayer($playerId);
        } else {
            // Show only public collections for other profiles
            $collections = $this->getPlayerCollections->publicByPlayer($playerId);
        }

        return $this->render('player_collections.html.twig', [
            'player' => $player,
            'collections' => $collections,
            'isOwnProfile' => $isOwnProfile,
        ]);
    }
}