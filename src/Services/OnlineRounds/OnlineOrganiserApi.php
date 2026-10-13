<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\OnlineRound;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionResultsEntryVoter;
use SpeedPuzzling\Web\Security\OnlineRoundsVoter;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The JSON endpoints of the organiser's online round tools - the control room's `online_live`, the review's list,
 * entry and confirm (docs/features/online-competitions/contracts/phase-1.md §5) - on the official results API's rules
 * (OfficialResultsApi: JSON both ways, `private, no-store`, 401 instead of a login redirect, writes with the stateless
 * CSRF header):
 * - the round must exist (404 `round_not_found`) and use online timing (404 `round_not_online`);
 * - the control room: ONLINE_ROUNDS_MANAGE (an organiser of the event - COMPETITION_EDIT - for whom online rounds are
 *   on);
 * - the review: COMPETITION_RESULTS_ENTRY (the official results' write right) and ONLINE_ROUNDS_MANAGE - the evidence
 *   photos stay with the organisers (README §8), so a results-only referee is refused.
 */
final readonly class OnlineOrganiserApi
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private OnlineRoundRepository $onlineRoundRepository,
        private OfficialResultsApi $officialResultsApi,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * @return array{round: CompetitionRound, onlineRound: OnlineRound, playerId: string}|JsonResponse the round, its
     *         online timing and the acting player - or the error to answer with
     */
    public function authorise(Request $request, string $roundId, bool $review, bool $write = false): array|JsonResponse
    {
        try {
            $round = $this->competitionRoundRepository->get($roundId);
        } catch (CompetitionRoundNotFound) {
            return OfficialResultsApi::error('round_not_found', JsonResponse::HTTP_NOT_FOUND);
        }

        $competitionId = $round->competition->id->toString();
        $playerId = $this->officialResultsApi->authorise(
            $request,
            $competitionId,
            $write,
            $review ? CompetitionResultsEntryVoter::COMPETITION_RESULTS_ENTRY : OnlineRoundsVoter::ONLINE_ROUNDS_MANAGE,
        );

        if ($playerId instanceof JsonResponse) {
            return $playerId;
        }

        if ($review && $this->authorizationChecker->isGranted(OnlineRoundsVoter::ONLINE_ROUNDS_MANAGE, $competitionId) === false) {
            return OfficialResultsApi::error('forbidden', JsonResponse::HTTP_FORBIDDEN);
        }

        $onlineRound = $this->onlineRoundRepository->ofRound($round->id->toString());

        if ($onlineRound === null) {
            return OfficialResultsApi::error('round_not_online', JsonResponse::HTTP_NOT_FOUND);
        }

        return ['round' => $round, 'onlineRound' => $onlineRound, 'playerId' => $playerId];
    }

    public static function wantsJson(Request $request): bool
    {
        return str_contains((string) $request->headers->get('Accept'), 'application/json');
    }
}
