<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCoPuzzlers;
use SpeedPuzzling\Web\Results\PersonSuggestion;
use SpeedPuzzling\Web\Results\TeamSuggestion;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Suggestions for the co-puzzler picker of the add/edit time form. Fetched only once the player
 * says they did not puzzle alone, so the (much more common) solo form pays nothing for it.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class MyCoPuzzlersController extends AbstractController
{
    public function __construct(
        private readonly GetCoPuzzlers $getCoPuzzlers,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly ImageThumbnailTwigExtension $imageThumbnail,
    ) {
    }

    #[Route(
        path: '/{_locale}/my-co-puzzlers.json',
        name: 'my_co_puzzlers',
        methods: ['GET'],
    )]
    public function __invoke(): JsonResponse
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->privateJson(['teams' => [], 'people' => []]);
        }

        $suggestions = $this->getCoPuzzlers->forPlayer($player->playerId);

        return $this->privateJson([
            'teams' => array_map(static fn(TeamSuggestion $team): array => [
                'id' => $team->teamId,
                'name' => $team->name,
                'size' => $team->size,
                'count' => $team->timesCount,
                'last' => $team->lastTogetherAt?->format('Y-m-d'),
                'score' => round($team->score, 4),
                'members' => $team->memberKeys,
            ], $suggestions->teams),
            'people' => array_map(fn(PersonSuggestion $person): array => [
                'key' => $person->key,
                'value' => $person->value,
                'label' => $person->label,
                'code' => $person->playerCode,
                'guest' => $person->isGuest(),
                'country' => $person->country?->name,
                'avatar' => $person->avatar !== null ? $this->imageThumbnail->thumbnailUrl($person->avatar, 'puzzle_small') : null,
                'count' => $person->timesCount,
                'pairCount' => $person->pairTimesCount,
                'last' => $person->lastTogetherAt?->format('Y-m-d'),
                'score' => round($person->score, 4),
                'pairScore' => round($person->pairScore, 4),
                'favorite' => $person->isFavorite,
            ], $suggestions->people),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function privateJson(array $data): JsonResponse
    {
        $response = new JsonResponse($data);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
