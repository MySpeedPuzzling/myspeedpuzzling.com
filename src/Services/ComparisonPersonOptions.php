<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Query\GetComparisonPeople;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;

/**
 * Players as the add sheet of the compare page draws them (comparison_add_controller.js): what its avatar needs - the
 * same inputs as _player_avatar.html.twig -, whether the viewer has them in favorites and, for members only, the skill
 * tier icon of the leaderboards. Tiers cost one statement for all lists of a response together.
 */
readonly final class ComparisonPersonOptions
{
    public function __construct(
        private GetComparisonPeople $getComparisonPeople,
        private ImageThumbnailTwigExtension $imageThumbnail,
    ) {
    }

    /**
     * @template TKey of string
     * @param array<TKey, list<PlayerIdentification>> $lists
     * @return array<TKey, list<array{ref: string, id: string, label: string, code: string, country: null|string, countryName: null|string, avatar: null|string, favorite: bool, tier?: string}>>
     */
    public function toJson(array $lists, PlayerProfile $viewer): array
    {
        $tiers = [];

        if ($viewer->activeMembership) {
            $ids = [];

            foreach ($lists as $players) {
                foreach ($players as $player) {
                    $ids[] = $player->playerId;
                }
            }

            $tiers = $this->getComparisonPeople->skillTierIcons($ids);
        }

        $favorites = array_flip(array_map(strtolower(...), $viewer->favoritePlayers));
        $json = [];

        foreach ($lists as $key => $players) {
            $json[$key] = array_map(function (PlayerIdentification $player) use ($viewer, $tiers, $favorites): array {
                $code = strtoupper($player->playerCode);
                $option = [
                    'ref' => ComparisonSubjectRef::player($player->playerId)->toString(),
                    'id' => strtolower($player->playerId),
                    'label' => $player->playerName ?? '#' . $code,
                    'code' => $code,
                    'country' => $player->playerCountry?->name,
                    'countryName' => $player->playerCountry?->value,
                    'avatar' => $player->playerAvatar !== null ? $this->imageThumbnail->thumbnailUrl($player->playerAvatar, 'puzzle_small') : null,
                    'favorite' => isset($favorites[strtolower($player->playerId)]),
                ];

                // Members-only data: the key is not there at all for anybody else
                if ($viewer->activeMembership) {
                    $option['tier'] = $tiers[$player->playerId] ?? 'unknown';
                }

                return $option;
            }, $players);
        }

        return $json;
    }
}
