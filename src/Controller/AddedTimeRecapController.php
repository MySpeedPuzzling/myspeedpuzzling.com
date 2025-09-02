<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AddedTimeRecapController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private GetPlayerProfile $getPlayerProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/cas-pridan/{timeId}',
            'en' => '/en/time-added/{timeId}',
            'es' => '/es/tiempo-anadido/{timeId}',
            'ja' => '/ja/時間追加済み/{timeId}',
            'fr' => '/fr/temps-ajoute/{timeId}',
            'de' => '/de/zeit-hinzugefuegt/{timeId}',
        ],
        name: 'added_time_recap',
    )]
    public function __invoke(
        Request $request,
        #[CurrentUser] User $user,
        string $timeId,
    ): Response {
        $solvingPuzzle = $this->getPlayerSolvedPuzzles->byTimeId($timeId);
        $player = $this->getPlayerProfile->byId($solvingPuzzle->playerId);

        return $this->render('added_time_recap.html.twig', [
            'solved_puzzle' => $solvingPuzzle,
        ]);
    }
}
