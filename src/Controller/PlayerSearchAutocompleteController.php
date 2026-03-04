<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\SearchPlayers;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class PlayerSearchAutocompleteController extends AbstractController
{
    public function __construct(
        private readonly SearchPlayers $searchPlayers,
    ) {
    }

    #[Route(
        path: '/{_locale}/player-search-autocomplete/',
        name: 'player_search_autocomplete',
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $search = $request->query->getString('query', '');

        if (strlen($search) < 2) {
            return new JsonResponse([]);
        }

        $players = $this->searchPlayers->fulltext($search, 15);

        $results = [];
        foreach ($players as $player) {
            $label = $player->playerName ?? $player->playerCode;

            if ($player->playerCountry !== null) {
                $label .= " ({$player->playerCountry->name})";
            }

            $results[] = [
                'value' => $player->playerId,
                'text' => $label,
            ];
        }

        return new JsonResponse($results);
    }
}
