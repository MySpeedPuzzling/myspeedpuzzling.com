<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetLastSolvedPuzzle;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class RecentActivityController extends AbstractController
{
    public function __construct(
        readonly private GetLastSolvedPuzzle $getLastSolvedPuzzle,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetRanking $getRanking,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/nedavna-aktivita',
            'en' => '/en/recent-activity',
            'es' => '/es/actividad-reciente',
            'ja' => '/ja/最近のアクティビティ',
            'fr' => '/fr/activite-recente',
            'de' => '/de/aktuelle-aktivitaet',
        ],
        name: 'recent_activity',
    )]
    public function __invoke(Request $request, #[CurrentUser] null|UserInterface $user): Response
    {
        $playerProfile = $this->retrieveLoggedUserProfile->getProfile();
        $userRanking = [];

        if ($playerProfile !== null) {
            $userRanking = $this->getRanking->allForPlayer($playerProfile->playerId);
        }

        return $this->render('recent_activity.html.twig', [
            'last_solved_puzzles' => $this->getLastSolvedPuzzle->limit(100),
            'ranking' => $userRanking,
        ]);
    }
}
