<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use SpeedPuzzling\Web\Query\GetRoundsWithOnlineTiming;
use SpeedPuzzling\Web\Security\OnlineRoundsVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether the organiser pages' round tools (official_results/_round_tool_links.html.twig) offer "Online timing" and
 * "Personal links" for a round (docs/features/online-competitions/): only for a round that uses online timing, and
 * only to a viewer who may set it up (ONLINE_ROUNDS_MANAGE).
 *
 * Costs nothing while the feature is off for the viewer (OnlineRoundsFeature - no statement), else one statement per
 * event and request: the event's rounds with their online timing (GetRoundsWithOnlineTiming), read once for every round
 * of it the page lists. The template asks only for rounds of online events.
 */
final class OnlineRoundTools implements ResetInterface
{
    /** @var array<string, array{competitionId: string, online: bool}> round id => its event and online timing */
    private array $rounds = [];

    /** @var array<string, true> rounds asked for that do not exist */
    private array $unknown = [];

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private OnlineRoundsFeature $onlineRoundsFeature,
        readonly private GetRoundsWithOnlineTiming $getRoundsWithOnlineTiming,
        readonly private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * @return null|array{competitionId: string} the round's event, for its links - null: no online tools for this round
     */
    public function forRound(string $roundId): null|array
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null || $this->onlineRoundsFeature->isAvailableTo($profile->playerId) === false) {
            return null;
        }

        $roundId = strtolower($roundId);

        if (isset($this->unknown[$roundId])) {
            return null;
        }

        if (isset($this->rounds[$roundId]) === false) {
            $this->rounds += $this->getRoundsWithOnlineTiming->sameEventAs($roundId);

            if (isset($this->rounds[$roundId]) === false) {
                $this->unknown[$roundId] = true;

                return null;
            }
        }

        $round = $this->rounds[$roundId];

        if ($round['online'] === false || $this->authorizationChecker->isGranted(OnlineRoundsVoter::ONLINE_ROUNDS_MANAGE, $round['competitionId']) === false) {
            return null;
        }

        return ['competitionId' => $round['competitionId']];
    }

    public function reset(): void
    {
        $this->rounds = [];
        $this->unknown = [];
    }
}
