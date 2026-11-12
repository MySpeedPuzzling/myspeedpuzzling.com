<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventResultsImport;

use SpeedPuzzling\Web\Query\GetEventResultClaims;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether the viewer is offered "This was my team" on a result (docs/features/event-results-import/README.md "Claiming a
 * spot"): signed in, the result still has an anonymous spot, the viewer is not in it and holds no spot of an imported
 * result of the same event (one round per imported event). Costs one statement per request, and only once a result with
 * an anonymous spot is asked about by a signed-in viewer - every other page view pays nothing.
 */
final class EventResultClaimability implements ResetInterface
{
    /** @var null|list<string> */
    private null|array $competitionsWithSpot = null;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetEventResultClaims $getEventResultClaims,
    ) {
    }

    /**
     * @param iterable<object> $players the result's people - anything with `anonymousSlot` and `playerId`
     */
    public function canClaim(iterable $players, null|string $competitionId): bool
    {
        $hasAnonymousSlot = false;
        $playerIds = [];

        foreach ($players as $player) {
            if (property_exists($player, 'anonymousSlot') && $player->anonymousSlot !== null) {
                $hasAnonymousSlot = true;
            }

            if (property_exists($player, 'playerId') && is_string($player->playerId)) {
                $playerIds[] = $player->playerId;
            }
        }

        if ($hasAnonymousSlot === false) {
            return false;
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null || in_array($profile->playerId, $playerIds, true)) {
            return false;
        }

        if ($competitionId === null) {
            return true;
        }

        $this->competitionsWithSpot ??= $this->getEventResultClaims->competitionsWithImportedSpot($profile->playerId);

        return in_array($competitionId, $this->competitionsWithSpot, true) === false;
    }

    public function reset(): void
    {
        $this->competitionsWithSpot = null;
    }
}
