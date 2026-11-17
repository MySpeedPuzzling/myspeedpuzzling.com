<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\OnlineTimingNotUsed;
use SpeedPuzzling\Web\Query\GetCompetitionRounds;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineLinkList;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineLinkUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The personal links of an online event - what its links page does (OnlineLinksController, docs/features/online-
 * competitions/README.md §5.4): whoever is going without a link gets one first (OnlineLinkList → GenerateOnlineTokens,
 * the message the page dispatches - a GET that writes, like the page; nothing is written when nobody misses one), then
 * `[{participantId, name, roundIds, url}]` for every participant who is going (not removed, not waitlisted), by name.
 * `url` is the language-free `/compete/{token}` address (OnlineLinkUrl::neutral(), like the CSV): the participant's own
 * profile or browser picks the language.
 *
 * An event no round of which uses online timing has no links - 409 (OnlineTimingNotUsed; the page shows none). The
 * answer is a list of secrets: `Cache-Control: no-store`, and never logged (InternalApiAuditSubscriber skips GETs, and
 * logs no body of any request).
 */
final class GetCompetitionOnlineLinksController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly OnlineRoundRepository $onlineRoundRepository,
        private readonly OnlineLinkList $onlineLinkList,
        private readonly OnlineLinkUrl $onlineLinkUrl,
        private readonly GetCompetitionRounds $getCompetitionRounds,
    ) {
    }

    #[Route(
        path: '/internal-api/competitions/{competitionId}/online-links',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $competitionId): JsonResponse
    {
        $competitionId = $this->competitionRepository->get($competitionId)->id->toString();

        if ($this->onlineRoundRepository->ofCompetition($competitionId) === []) {
            throw new OnlineTimingNotUsed();
        }

        $rows = $this->onlineLinkList->forCompetition($competitionId, null, usesOnlineTiming: true);
        $roundsByParticipant = $this->getCompetitionRounds->forAllCompetitionParticipants($competitionId);
        $links = [];

        foreach ($rows as $row) {
            if ($row->waitlisted || $row->token === null) {
                continue;
            }

            $links[] = [
                'participantId' => $row->participantId,
                'name' => $row->name,
                'roundIds' => array_values($roundsByParticipant[$row->participantId] ?? []),
                'url' => $this->onlineLinkUrl->neutral($row->token),
            ];
        }

        $response = new JsonResponse($links);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
