<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AddedTrackingRecapController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/sledovani-pridano/{trackingId}',
            'en' => '/en/tracking-added/{trackingId}',
            'es' => '/es/seguimiento-anadido/{trackingId}',
            'ja' => '/ja/トラッキング追加済み/{trackingId}',
            'fr' => '/fr/suivi-ajoute/{trackingId}',
            'de' => '/de/tracking-hinzugefuegt/{trackingId}',
        ],
        name: 'added_tracking_recap',
    )]
    public function __invoke(
        Request $request,
        #[CurrentUser] User $user,
        string $trackingId,
    ): Response {
        $trackedPuzzle = $this->getPlayerSolvedPuzzles->byTimeId($trackingId);

        return $this->render('added_tracking_recap.html.twig', [
            'tracked_puzzle' => $trackedPuzzle,
        ]);
    }
}
