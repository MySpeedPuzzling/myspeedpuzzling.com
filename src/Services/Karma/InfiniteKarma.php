<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Karma;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Kudos (Jan 2026-10-12): the players whose karma shows as "∞" - golden and glowing on the profile chip, the karma
 * list and their karma pages - a thank-you to the people who run the site with us. Display only: their karma is
 * derived and ranked like everybody's (docs/features/karma.md). The ids come from the environment
 * (KARMA_INFINITE_PLAYER_IDS, comma-separated) - never from the public repository.
 */
final readonly class InfiniteKarma
{
    /** @var array<string, true> */
    private array $playerIds;

    public function __construct(
        #[Autowire(env: 'KARMA_INFINITE_PLAYER_IDS')]
        string $playerIds,
    ) {
        $ids = [];

        foreach (explode(',', $playerIds) as $id) {
            $id = strtolower(trim($id));

            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        $this->playerIds = $ids;
    }

    public function isInfinite(null|string $playerId): bool
    {
        return $playerId !== null && isset($this->playerIds[strtolower($playerId)]);
    }
}
