<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Query\GetCoPuzzlerChips;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * What the add/edit time form needs to render the Solo / Pair / Team picker
 * (docs/features/pairs-and-teams/README.md). For a solo form that is nothing at all - no query.
 */
readonly final class CoPuzzlerPicker
{
    public function __construct(
        private GetCoPuzzlerChips $getCoPuzzlerChips,
        private Security $security,
        private bool $pairsTeamsPickerPublic,
    ) {
    }

    /**
     * Rollout flag PAIRS_TEAMS_PICKER_PUBLIC (docs/features/feature_flags.md): admins only until flipped.
     */
    public function isEnabled(): bool
    {
        return $this->pairsTeamsPickerPublic || $this->security->isGranted('ADMIN_ACCESS');
    }

    /**
     * The "Add time" link of the Pairs & teams page: ?team=<id> starts the form with that pair/team chosen.
     *
     * @return list<string>
     */
    public function groupPlayersOfTeam(string $teamId, string $viewerPlayerId): array
    {
        return $this->getCoPuzzlerChips->groupPlayersOfTeam($teamId, $viewerPlayerId);
    }

    /**
     * @param array<string> $groupPlayers The form's current co-puzzlers: "#CODE" or a guest name
     * @param null|string $trackerPlayerId Whoever tracked the time, when somebody else is editing it
     * @return array{
     *     chips: list<array{key: string, value: string, label: string, code: null|string, guest: bool, country: null|string, avatar: null|string}>,
     *     tracker: null|array{key: string, value: string, label: string, code: null|string, guest: bool, country: null|string, avatar: null|string},
     *     viewerKey: null|string,
     * }
     */
    public function formState(array $groupPlayers, null|string $trackerPlayerId = null, null|string $viewerPlayerId = null): array
    {
        if ($this->isEnabled() === false) {
            return ['chips' => [], 'tracker' => null, 'viewerKey' => null];
        }

        return [
            'chips' => $groupPlayers === [] ? [] : $this->getCoPuzzlerChips->forGroupPlayers($groupPlayers),
            'tracker' => $trackerPlayerId === null ? null : $this->getCoPuzzlerChips->forPlayerId($trackerPlayerId),
            // A player's member key is their id - how the picker recognises the viewer's own chip
            'viewerKey' => $trackerPlayerId === null ? null : $viewerPlayerId,
        ];
    }
}
