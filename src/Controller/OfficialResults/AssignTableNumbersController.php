<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OfficialResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\InvalidTableNumbers;
use SpeedPuzzling\Web\Message\AssignTableNumbers;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Seating in one write (AssignTableNumbers): `{"assignments": [{"entry": "team:<id>", "number": 12}, ...]}` → the
 * changed entries; 422 `invalid_table_numbers` with `problems` (nothing written).
 */
final class AssignTableNumbersController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly GetRoundResultEntries $getRoundResultEntries,
        private readonly OfficialResultsApi $api,
        private readonly OfficialResultsLiveUpdates $liveUpdates,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/official-results/rounds/{roundId}/table-numbers',
        name: 'official_results_assign_table_numbers',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId): JsonResponse
    {
        $round = $this->roundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $authorised = $this->api->authorise($request, $competitionId, write: true);

        if ($authorised instanceof JsonResponse) {
            return $authorised;
        }

        $body = OfficialResultsApi::body($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $assignments = [];
        $rawAssignments = $body['assignments'] ?? null;

        if (!is_array($rawAssignments) || !array_is_list($rawAssignments)) {
            return OfficialResultsApi::error('invalid_assignments', JsonResponse::HTTP_BAD_REQUEST);
        }

        foreach ($rawAssignments as $assignment) {
            if (!is_array($assignment) || !is_string($assignment['entry'] ?? null) || !array_key_exists('number', $assignment) || !(is_int($assignment['number']) || $assignment['number'] === null)) {
                return OfficialResultsApi::error('invalid_assignments', JsonResponse::HTTP_BAD_REQUEST);
            }

            $assignments[] = ['entry' => $assignment['entry'], 'number' => $assignment['number']];
        }

        try {
            $envelope = $this->messageBus->dispatch(new AssignTableNumbers($competitionId, $round->id->toString(), $assignments));
        } catch (InvalidTableNumbers $invalid) {
            return OfficialResultsApi::error('invalid_table_numbers', JsonResponse::HTTP_UNPROCESSABLE_ENTITY, [
                'problems' => array_map(fn (array $problem): array => [
                    ...$problem,
                    'message' => $this->translator->trans('official_results.reason.' . $problem['reason']),
                ], $invalid->problems),
            ]);
        }

        /** @var list<string> $changed */
        $changed = $envelope->last(HandledStamp::class)?->getResult() ?? [];
        $this->liveUpdates->entriesChanged($round->id->toString(), $changed);

        return OfficialResultsApi::json([
            'changed' => count($changed),
            'entries' => $this->getRoundResultEntries->byRefs($round->id->toString(), $changed),
        ]);
    }
}
