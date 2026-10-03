<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\SearchPlayers;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Services\ComparisonPersonOptions;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The search of the Solo add sheet of the compare page (docs/features/player-comparison.md "Add sheet"): players by
 * name or #code, drawn like the sheet's lists - favorite star, members see the skill tier. A private player hidden from
 * the viewer is left out even when their exact code was typed: the add would be refused ("Visibility").
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ComparisonPlayerSearchController extends AbstractController
{
    public const int LIMIT = 15;

    public function __construct(
        private readonly SearchPlayers $searchPlayers,
        private readonly ComparisonPersonOptions $comparisonPersonOptions,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: '/{_locale}/compare/players.json',
        name: 'comparison_player_search',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        // "#ABC" is a code - the search knows codes without the hash
        $query = ltrim(trim($request->query->getString('query')), '#');

        if ($profile === null || mb_strlen($query) < 2) {
            return $this->privateJson([]);
        }

        $players = array_values(array_filter(
            $this->searchPlayers->fulltext($query, self::LIMIT),
            static fn (PlayerIdentification $player): bool => $player->isPrivate === false,
        ));

        return $this->privateJson($this->comparisonPersonOptions->toJson(['results' => $players], $profile)['results']);
    }

    /**
     * @param array<mixed> $data
     */
    private function privateJson(array $data): JsonResponse
    {
        $response = new JsonResponse($data);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
