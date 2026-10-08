<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;

/**
 * Players found by SearchPlayers in the shape the co-puzzler picker and the participants sheet's profile typeahead
 * draw their own options from (`player_search_autocomplete?format=co-puzzler`, `participants_sheet_player_search`) -
 * the plain fields of MyCoPuzzlersController's people, never ready-made HTML.
 */
readonly final class CoPuzzlerSearchRows
{
    public function __construct(
        private ImageThumbnailTwigExtension $imageThumbnail,
    ) {
    }

    /**
     * @param list<PlayerIdentification> $players
     * @return list<array{key: string, value: string, label: string, code: string, guest: false, country: null|string, avatar: null|string, hidden: bool}>
     */
    public function of(array $players): array
    {
        return array_map(fn (PlayerIdentification $player): array => [
            'key' => $player->playerId,
            'value' => '#' . strtoupper($player->playerCode),
            'label' => $player->playerName ?? '#' . strtoupper($player->playerCode),
            'code' => strtoupper($player->playerCode),
            'guest' => false,
            'country' => $player->playerCountry?->name,
            'avatar' => $player->playerAvatar !== null ? $this->imageThumbnail->thumbnailUrl($player->playerAvatar, 'puzzle_small') : null,
            // A private player found by their exact code, hidden from this viewer: a co-puzzler may be added by
            // code, the compare page does not offer them (docs/features/player-comparison.md "Visibility")
            'hidden' => $player->isPrivate,
        ], $players);
    }
}
