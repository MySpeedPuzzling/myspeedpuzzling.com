<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PairsAndTeams;

use SpeedPuzzling\Web\Query\GetPuzzlingTeamDetail;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\PuzzlingTeamDetail;
use SpeedPuzzling\Web\Results\PuzzlingTeamTime;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public page of a pair/team: who they are and what they solved together. Every pair/team result on
 * the site links here. Stats are for members (docs/features/pairs-and-teams/README.md, D7).
 */
final class PuzzlingTeamDetailController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzlingTeamDetail $getPuzzlingTeamDetail,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private HiddenPlayers $hiddenPlayers,
    ) {
    }

    #[Route(
        path: '/{_locale}/teams/{teamId}',
        name: 'puzzling_team_detail',
        methods: ['GET'],
    )]
    public function __invoke(string $teamId): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        $team = $this->getPuzzlingTeamDetail->byId($teamId, $viewer?->playerId);
        $times = $this->getPuzzlingTeamDetail->times($teamId);

        return $this->render('pairs_and_teams/team_detail.html.twig', [
            'team' => $team,
            'times' => $times,
            'related_teams' => $this->getPuzzlingTeamDetail->relatedTeams($team, $viewer?->playerId),
            'is_member' => $team->hasMember($viewer?->playerId),
            'stats' => $viewer !== null && $viewer->activeMembership ? $this->stats($times) : null,
            'comparison' => $this->comparison($team, $viewer),
            'comparison_ref' => ComparisonSubjectRef::team($team->teamId)->toString(),
        ]);
    }

    /**
     * "Add to comparison" / "In comparison · Open" (docs/features/player-comparison.md) - from the viewer's own profile
     * row, no query. Offered only where AddComparisonSubjectHandler would accept it (ComparisonSubjectVisibility):
     * nobody of the team is hidden from the viewer (a member viewer still sees the page), and the viewer is in it or
     * at least one registered member is visible to them.
     *
     * @return null|'add'|'open'
     */
    private function comparison(PuzzlingTeamDetail $team, null|PlayerProfile $viewer): null|string
    {
        if ($viewer === null) {
            return null;
        }

        if ($viewer->comparisonLineUp->contains(ComparisonSubjectRef::team($team->teamId))) {
            return 'open';
        }

        $somebodyVisible = $team->hasMember($viewer->playerId);

        foreach ($team->members as $member) {
            if ($member->playerId === null) {
                continue;
            }

            if ($this->hiddenPlayers->isHidden($member->playerId)) {
                return null;
            }

            $somebodyVisible = $somebodyVisible || $member->isPrivate === false;
        }

        return $somebodyVisible ? 'add' : null;
    }

    /**
     * @param list<PuzzlingTeamTime> $times
     * @return array{timed: int, relaxed: int, pieces: int, puzzles: int, first: null|\DateTimeImmutable, best: array<int, PuzzlingTeamTime>}
     */
    private function stats(array $times): array
    {
        $best = [];
        $pieces = 0;
        $timed = 0;
        $puzzles = [];
        $first = null;

        foreach ($times as $time) {
            $pieces += $time->piecesCount;
            $puzzles[$time->puzzleId] = true;
            $first = $first === null || $time->solvedAt < $first ? $time->solvedAt : $first;

            if ($time->time === null) {
                continue;
            }

            $timed++;

            if (!isset($best[$time->piecesCount]) || $time->time < $best[$time->piecesCount]->time) {
                $best[$time->piecesCount] = $time;
            }
        }

        ksort($best);

        return [
            'timed' => $timed,
            'relaxed' => count($times) - $timed,
            'pieces' => $pieces,
            'puzzles' => count($puzzles),
            'first' => $first,
            'best' => $best,
        ];
    }
}
