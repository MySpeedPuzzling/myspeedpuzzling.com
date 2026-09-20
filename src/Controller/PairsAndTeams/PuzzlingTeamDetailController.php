<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PairsAndTeams;

use SpeedPuzzling\Web\Query\GetPuzzlingTeamDetail;
use SpeedPuzzling\Web\Results\PuzzlingTeamTime;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
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
        ]);
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
