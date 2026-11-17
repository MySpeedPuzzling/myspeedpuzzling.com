<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OfficialResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\TableNumbersCopyRefused;
use SpeedPuzzling\Web\Message\CopyRoundTableNumbers;
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
 * "Use these tables in later rounds of this category" (CopyRoundTableNumbers, competition toolkit W3):
 * `{"targets": [{"round": "<id>", "hash": "<hash from the confirm>"}, ...]}` → `changed` (round id => how many tables
 * changed); 422 `copy_refused` with `problems` (nothing written in any round). Every changed round's pages follow
 * over Mercure.
 */
final class CopyTableNumbersController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly OfficialResultsApi $api,
        private readonly OfficialResultsLiveUpdates $liveUpdates,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/official-results/rounds/{roundId}/copy-table-numbers',
        name: 'official_results_copy_table_numbers',
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

        $rawTargets = $body['targets'] ?? null;

        if (!is_array($rawTargets) || !array_is_list($rawTargets)) {
            return OfficialResultsApi::error('invalid_targets', JsonResponse::HTTP_BAD_REQUEST);
        }

        $targets = [];
        foreach ($rawTargets as $target) {
            if (
                !is_array($target)
                || !is_string($target['round'] ?? null)
                || preg_match('/^' . FirstTryConflictsController::ID_REQUIREMENT . '$/', $target['round']) !== 1
                || !is_string($target['hash'] ?? null)
                || isset($targets[strtolower($target['round'])])
            ) {
                return OfficialResultsApi::error('invalid_targets', JsonResponse::HTTP_BAD_REQUEST);
            }

            $targets[strtolower($target['round'])] = $target['hash'];
        }

        try {
            $envelope = $this->messageBus->dispatch(new CopyRoundTableNumbers($competitionId, $round->id->toString(), $targets));
        } catch (TableNumbersCopyRefused $refused) {
            return OfficialResultsApi::error('copy_refused', JsonResponse::HTTP_UNPROCESSABLE_ENTITY, [
                'problems' => array_map(fn (array $problem): array => [
                    ...$problem,
                    'message' => $this->translator->trans('seating.copy.reason.' . $problem['reason']),
                ], $refused->problems),
            ]);
        }

        /** @var array<string, list<string>> $changed */
        $changed = $envelope->last(HandledStamp::class)?->getResult() ?? [];

        foreach ($changed as $targetRoundId => $refs) {
            $this->liveUpdates->entriesChanged($targetRoundId, $refs);
        }

        return OfficialResultsApi::json([
            'changed' => array_map(count(...), $changed),
        ]);
    }
}
