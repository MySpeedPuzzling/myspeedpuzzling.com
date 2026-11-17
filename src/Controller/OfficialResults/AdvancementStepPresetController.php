<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OfficialResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetCompetitionAdvancementSteps;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Results\SeededEntry;
use SpeedPuzzling\Web\Services\AdvancementSeeding;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Value\AdvancementStepSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Mark top N overall" of an advancement step (competition toolkit A8): who of all the step's source rounds together is
 * among the best N by the advancement seed (AdvancementSeeding::topOverall - the same order AdvanceQualified uses), over
 * the saved results. Read only: the desk shows it in its Top N review dialog, and only "Apply" there writes - ordinary
 * `qualified` changes of its own round through RecordRoundResults. The event's organisers only (like the desk).
 *
 * `?count=` overrides the step's number (the organiser edits it in the dialog). Answer: `selected` (seed order, each with
 * its round) and `tiedAtCut` (refs sharing the last place that fits, when they take the list past N).
 */
final class AdvancementStepPresetController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionAdvancementSteps $getCompetitionAdvancementSteps,
        private readonly GetRoundResultEntries $getRoundResultEntries,
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly OfficialResultsApi $api,
    ) {
    }

    #[Route(
        path: '/{_locale}/official-results/advancement-steps/{stepId}/preset',
        name: 'official_results_advancement_step_preset',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'stepId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $stepId): JsonResponse
    {
        $step = $this->getCompetitionAdvancementSteps->byId($stepId);
        $authorised = $this->api->authorise($request, $step->competitionId, write: false);

        if ($authorised instanceof JsonResponse) {
            return $authorised;
        }

        $count = $request->query->has('count') ? $request->query->getInt('count') : ($step->settings->qualifyCount ?? 0);
        $count = max(0, min(AdvancementStepSettings::QUALIFY_MAX, $count));

        $entriesByRound = [];
        $piecesCountByRound = [];

        foreach ($step->settings->sourceRoundIds as $roundId) {
            $round = $this->roundRepository->get($roundId);

            // A round moved to another event meanwhile is no source of this step any more
            if ($round->competition->id->toString() !== $step->competitionId) {
                continue;
            }

            $entriesByRound[$roundId] = $this->getRoundResultEntries->forRound($roundId);
            $piecesCountByRound[$roundId] = $round->singlePuzzlePiecesCount();
        }

        $top = AdvancementSeeding::topOverall($entriesByRound, $piecesCountByRound, $count);

        return OfficialResultsApi::json([
            'count' => $count,
            'selected' => array_map(static fn (SeededEntry $seeded): array => [
                'ref' => $seeded->entry->ref->toString(),
                'roundId' => $seeded->sourceRoundId,
                'seed' => $seeded->seed,
            ], $top['selected']),
            'tiedAtCut' => array_map(static fn (SeededEntry $seeded): string => $seeded->entry->ref->toString(), $top['tiedAtCut']),
        ]);
    }
}
