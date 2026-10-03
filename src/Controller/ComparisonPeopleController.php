<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetComparisonPeople;
use SpeedPuzzling\Web\Services\ComparisonPersonOptions;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The lists of the Solo add sheet of the compare page (docs/features/player-comparison.md "Add sheet"), fetched by
 * comparison_add_controller.js once the sheet opens: ALL of the viewer's favorites (the sheet shows 8, then "Show all"
 * and a filter of its own) and a few people they puzzle with who are not favorites.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ComparisonPeopleController extends AbstractController
{
    public const int CO_PUZZLERS_LIMIT = 6;

    public function __construct(
        private readonly GetComparisonPeople $getComparisonPeople,
        private readonly ComparisonPersonOptions $comparisonPersonOptions,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: '/{_locale}/compare/people.json',
        name: 'comparison_people',
        methods: ['GET'],
    )]
    public function __invoke(): JsonResponse
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->privateJson(['favorites' => [], 'coPuzzlers' => []]);
        }

        $people = $this->getComparisonPeople->forViewer($profile->playerId, self::CO_PUZZLERS_LIMIT);

        return $this->privateJson($this->comparisonPersonOptions->toJson([
            'favorites' => $people->favorites,
            'coPuzzlers' => $people->coPuzzlers,
        ], $profile));
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
