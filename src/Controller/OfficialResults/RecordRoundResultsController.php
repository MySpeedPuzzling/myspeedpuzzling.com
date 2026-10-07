<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OfficialResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\RecordRoundResults;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Results\RecordedRoundResults;
use SpeedPuzzling\Web\Results\RoundResultChangeOutcome;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Security\CompetitionResultsEntryVoter;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\MercureTopicCollector;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use SpeedPuzzling\Web\Services\RoundResultChangesParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The organiser devices' change sets (RecordRoundResults): `{"changes": [...], "dryRun": false}` → one outcome per
 * change, the entries as they are now, and - after the commit - a private Mercure update for the other devices.
 * Organisers and referees may send them; a referee's table number and qualified changes are refused one by one.
 * docs/features/competitions-management/official-results.md - request and response documented there.
 */
final class RecordRoundResultsController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly GetRoundResultEntries $getRoundResultEntries,
        private readonly OfficialResultsApi $api,
        private readonly OfficialResultsLiveUpdates $liveUpdates,
        private readonly TranslatorInterface $translator,
        private readonly MercureTopicCollector $mercureTopicCollector,
    ) {
    }

    #[Route(
        path: '/{_locale}/official-results/rounds/{roundId}/changes',
        name: 'official_results_record',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId): JsonResponse
    {
        $round = $this->roundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $playerId = $this->api->authorise($request, $competitionId, write: true, attribute: CompetitionResultsEntryVoter::COMPETITION_RESULTS_ENTRY);

        if ($playerId instanceof JsonResponse) {
            return $playerId;
        }

        // Keeps the round's private topic in the answer's Mercure cookie (see RoundResultsStateController)
        $this->mercureTopicCollector->addTopic(OfficialResultsLiveUpdates::topic($round->id->toString()));

        $body = OfficialResultsApi::body($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        try {
            $changes = RoundResultChangesParser::parse($body['changes'] ?? null);
        } catch (\InvalidArgumentException $exception) {
            return OfficialResultsApi::error('invalid_changes', JsonResponse::HTTP_BAD_REQUEST, ['message' => $exception->getMessage()]);
        }

        $dryRun = ($body['dryRun'] ?? false) === true;

        $envelope = $this->messageBus->dispatch(new RecordRoundResults(
            competitionId: $competitionId,
            roundId: $round->id->toString(),
            actingPlayerId: $playerId,
            changes: $changes,
            dryRun: $dryRun,
            // A referee (live-results.md "Referees") enters results only - tables and qualified marks are refused
            resultsOnly: $this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId) === false,
        ));

        $recorded = $envelope->last(HandledStamp::class)?->getResult();
        assert($recorded instanceof RecordedRoundResults);

        // Committed by now - the other devices of the round learn about it
        $this->liveUpdates->entriesChanged($round->id->toString(), $recorded->changedEntryRefs);

        $refs = array_values(array_unique(array_filter(array_map(
            static fn (RoundResultChangeOutcome $outcome): null|string => $outcome->entryRef,
            $recorded->outcomes,
        ))));

        return OfficialResultsApi::json([
            'dryRun' => $recorded->dryRun,
            'outcomes' => array_map(fn (RoundResultChangeOutcome $outcome): array => [
                ...$outcome->jsonSerialize(),
                'message' => $outcome->reason !== null ? $this->translator->trans('official_results.reason.' . $outcome->reason) : null,
            ], $recorded->outcomes),
            'entries' => $this->getRoundResultEntries->byRefs($round->id->toString(), $refs),
        ]);
    }
}
