<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Rating;

use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetTransactionRatings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlayerRatingsController extends AbstractController
{
    public function __construct(
        readonly private GetTransactionRatings $getTransactionRatings,
        readonly private GetPlayerProfile $getPlayerProfile,
    ) {
    }

    #[Route(
        path: '/en/player/{playerId}/ratings',
        name: 'player_ratings',
    )]
    public function __invoke(string $playerId, Request $request): Response
    {
        $player = $this->getPlayerProfile->byId($playerId);
        $ratings = $this->getTransactionRatings->forPlayer($playerId);
        $summary = $this->getTransactionRatings->averageForPlayer($playerId);

        if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
            return $this->render('rating/player_ratings_modal.html.twig', [
                'player' => $player,
                'ratings' => $ratings,
                'summary' => $summary,
            ]);
        }

        return $this->render('rating/player_ratings.html.twig', [
            'player' => $player,
            'ratings' => $ratings,
            'summary' => $summary,
        ]);
    }
}
