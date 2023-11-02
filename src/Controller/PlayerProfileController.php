<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class PlayerProfileController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
    )
    {
    }

    #[Route(path: '/profil-hrace/{playerId}', name: 'player_profile', methods: ['GET'])]
    public function __invoke(string $playerId): Response
    {
        try {
            $player = $this->getPlayerProfile->byId($playerId);
        } catch (PlayerNotFound) {
            $this->addFlash('primary', 'Hráč, kterého jste zkoušeli hledat, u nás není!');

            return $this->redirectToRoute('ladder');
        }

        return $this->render('player-profile.html.twig', [
            'player' => $player,
            'puzzles' => $this->getPlayerSolvedPuzzles->byPlayerId($playerId),
        ]);
    }
}
