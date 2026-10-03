<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\SearchComparisonTeams;
use SpeedPuzzling\Web\Results\ComparisonTeamSearchResult;
use SpeedPuzzling\Web\Results\PuzzlingTeamMemberView;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ComparisonKind;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Pairs / Teams add sheet of the compare page (docs/features/player-comparison.md D12), fetched by
 * comparison_add_controller.js only once the sheet is used: without a query the viewer's own pairs/teams (the
 * suggestions), with 2+ characters any pair/team the viewer may see - their own first, marked "You're in it".
 * Members are named the way this viewer may see them.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ComparisonTeamSearchController extends AbstractController
{
    private const int LIMIT = 20;

    public function __construct(
        private readonly SearchComparisonTeams $searchComparisonTeams,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/compare/teams.json',
        name: 'comparison_team_search',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        $kind = ComparisonKind::tryFrom($request->query->getString('kind'));
        $query = trim($request->query->getString('query'));

        if ($profile === null || $kind === null || $kind === ComparisonKind::Solo) {
            return $this->privateJson([]);
        }

        if ($query === '') {
            $teams = $this->searchComparisonTeams->forViewer($profile->playerId, $kind, self::LIMIT);
        } elseif (mb_strlen(ltrim($query, '#')) >= 2) {
            $teams = $this->searchComparisonTeams->search($query, $kind, $profile->playerId, self::LIMIT);
        } else {
            $teams = [];
        }

        return $this->privateJson(array_map(fn (ComparisonTeamSearchResult $team): array => [
            'ref' => $team->ref()->toString(),
            'label' => $team->name ?? $this->membersLabel($team, $profile->playerId),
            'members' => $this->membersLabel($team, $profile->playerId),
            'named' => $team->name !== null,
            'size' => $team->size,
            'count' => $team->timesCount,
            'mine' => $team->includesViewer,
        ], $teams));
    }

    private function membersLabel(ComparisonTeamSearchResult $team, string $viewerId): string
    {
        $names = array_map(fn (PuzzlingTeamMemberView $member): string => match (true) {
            $member->playerId !== null && $member->playerId === $viewerId => $this->translator->trans('comparison.you'),
            $member->isPrivate => $this->translator->trans('secret_puzzler_name'),
            default => $member->playerName ?? $member->guestName ?? '#' . $member->playerCode,
        }, $team->members);

        return implode($team->size === 2 ? ' & ' : ', ', $names);
    }

    /**
     * @param array<mixed> $data
     */
    private function privateJson(array $data): JsonResponse
    {
        $response = new JsonResponse($data);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
