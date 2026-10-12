<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A player's results (docs/features/navigation README §4.7, PROF-00): today's results list of the profile under the
 * player header, so the Menu's Results row and the player tabs never link to a missing page. PROF-05a… replace its
 * content. A player hidden from the viewer (blocked either way, unknown id) is a 404; a private profile the viewer may
 * not see goes to the profile, which explains it (implementation plan decision 69).
 */
final class PlayerResultsController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/vysledky-hrace/{playerId}',
            'en' => '/en/player-results/{playerId}',
            'es' => '/es/resultados-jugador/{playerId}',
            'ja' => '/ja/プレイヤー結果/{playerId}',
            'fr' => '/fr/resultats-joueur/{playerId}',
            'de' => '/de/spieler-ergebnisse/{playerId}',
        ],
        name: 'player_results',
        methods: ['GET'],
    )]
    public function __invoke(string $playerId): Response
    {
        // PlayerNotFound extends NotFoundHttpException and bubbles up as 404 (also for a player hidden from the viewer)
        $player = $this->getPlayerProfile->byId($playerId);

        // isPrivate = hidden from this viewer (PrivateProfileAccess): an allowed friend and the owner see the list
        if ($player->isPrivate && $this->retrieveLoggedUserProfile->getProfile()?->playerId !== $player->playerId) {
            return $this->redirectToRoute('player_profile', ['playerId' => $player->playerId]);
        }

        return $this->render('player_results.html.twig', [
            'player' => $player,
        ]);
    }
}
