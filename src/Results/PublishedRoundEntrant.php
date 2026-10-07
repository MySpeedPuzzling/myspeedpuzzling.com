<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

/**
 * One person of an official results row on the public round page - a solo entrant or a member of a pair/team - as
 * the organiser recorded them. The display properties follow _leaderboard_player.html.twig: `playerId` is set only
 * for a linked player the viewer may see (their profile is linked); a private player the viewer may not see keeps the
 * organiser's name, without a link, avatar or anything of their profile.
 */
readonly final class PublishedRoundEntrant
{
    public function __construct(
        // The organiser's participant name - the official record
        public string $playerName,
        public null|CountryCode $playerCountry,
        // The linked player when the viewer may see them, else null
        public null|string $playerId,
        public null|string $playerAvatar,
        // The linked player, whoever it is - never rendered: who the viewer is and what the add-time form fills in
        public null|string $linkedPlayerId,
        public null|string $linkedPlayerCode,
    ) {
    }
}
