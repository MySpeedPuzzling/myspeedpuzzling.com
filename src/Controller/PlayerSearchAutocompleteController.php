<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\SearchPlayers;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class PlayerSearchAutocompleteController extends AbstractController
{
    public function __construct(
        private readonly SearchPlayers $searchPlayers,
        private readonly ImageThumbnailTwigExtension $imageThumbnail,
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

        // The co-puzzler picker draws its own chips and submits player codes, so it wants the plain
        // fields - in the shape of MyCoPuzzlersController's people - rather than ready-made HTML
        if ($request->query->getString('format') === 'co-puzzler') {
            return new JsonResponse(array_map(fn(PlayerIdentification $player): array => [
                'key' => $player->playerId,
                'value' => '#' . strtoupper($player->playerCode),
                'label' => $player->playerName ?? '#' . strtoupper($player->playerCode),
                'code' => strtoupper($player->playerCode),
                'guest' => false,
                'country' => $player->playerCountry?->name,
                'avatar' => $player->playerAvatar !== null ? $this->imageThumbnail->thumbnailUrl($player->playerAvatar, 'puzzle_small') : null,
            ], $players));
        }

        $results = [];
        foreach ($players as $player) {
            $name = htmlspecialchars($player->playerName ?? $player->playerCode);
            $code = htmlspecialchars($player->playerCode);

            $avatar = '';
            if ($player->playerAvatar !== null) {
                $avatarUrl = htmlspecialchars($this->imageThumbnail->thumbnailUrl($player->playerAvatar, 'puzzle_small'));
                $avatar = <<<HTML
<img alt="" class="rounded-circle me-2" style="width: 24px; height: 24px; object-fit: cover;" src="{$avatarUrl}">
HTML;
            } else {
                $avatar = '<i class="ci-user me-2"></i>';
            }

            $flag = '';
            if ($player->playerCountry !== null) {
                $flag = '<span class="shadow-custom fi fi-' . $player->playerCountry->name . ' me-1"></span> ';
            }

            $html = <<<HTML
<div class="d-flex align-items-center">{$avatar}{$flag}<span>{$name}</span><small class="text-muted ms-1">#{$code}</small></div>
HTML;

            $results[] = [
                'value' => $player->playerId,
                'text' => $html,
            ];
        }

        return new JsonResponse($results);
    }
}
