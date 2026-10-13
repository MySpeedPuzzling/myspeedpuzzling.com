<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\PlayerResults\PlayerResultsBuilder;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\PlayerResultsCriteria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A player's Results (docs/features/navigation README §4.7, PROF-05a): every result, searchable and filterable, By
 * puzzle or Every result - a plain server-rendered page whose state is the URL (PlayerResultsCriteria; members-only
 * values of anybody else are dropped there), not a Live component, so links work without JavaScript and history can
 * be pushed (implementation plan decision 69). A `Turbo-Frame: player-results` request gets the list region alone.
 *
 * A player the viewer blocked (as on the profile: GetPlayerProfile::byId()) or an unknown id is a 404; a private
 * profile the viewer may not see goes to the profile, which explains it.
 */
final class PlayerResultsController extends AbstractController
{
    public const string FRAME_ID = 'player-results';

    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PlayerResultsBuilder $playerResultsBuilder,
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
    public function __invoke(Request $request, string $playerId): Response
    {
        // PlayerNotFound extends NotFoundHttpException and bubbles up as 404 (also for a player hidden from the viewer)
        $player = $this->getPlayerProfile->byId($playerId);
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        // isPrivate = hidden from this viewer (PrivateProfileAccess): an allowed friend and the owner see the list
        if ($player->isPrivate && $viewer?->playerId !== $player->playerId) {
            return $this->redirectToRoute('player_profile', ['playerId' => $player->playerId]);
        }

        $criteria = PlayerResultsCriteria::fromRequest($request, isMember: $viewer?->activeMembership === true);
        $results = $this->playerResultsBuilder->build($player->playerId, $criteria);

        $frameOnly = $request->headers->get('Turbo-Frame') === self::FRAME_ID;

        $response = $this->render($frameOnly ? 'player/results/_frame.html.twig' : 'player_results.html.twig', [
            'player' => $player,
            'results' => $results,
        ]);

        // The page and its frame share one URL
        $response->setVary('Turbo-Frame', false);

        return $response;
    }
}
