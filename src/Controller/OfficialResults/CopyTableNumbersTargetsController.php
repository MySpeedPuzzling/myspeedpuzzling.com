<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OfficialResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\SameTablesCopy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The confirm of "Use these tables in later rounds of this category" (competition toolkit W3, seating.md): the later
 * rounds of the same kind with how many entrants get their table from this round, how many tables change, and the
 * `hash` the copy sends back (official_results_copy_table_numbers). Nothing is written.
 */
final class CopyTableNumbersTargetsController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly SameTablesCopy $sameTablesCopy,
        private readonly OfficialResultsApi $api,
    ) {
    }

    #[Route(
        path: '/{_locale}/official-results/rounds/{roundId}/copy-table-numbers',
        name: 'official_results_copy_table_numbers_targets',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $roundId): JsonResponse
    {
        $round = $this->roundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $authorised = $this->api->authorise($request, $competitionId, write: false);

        if ($authorised instanceof JsonResponse) {
            return $authorised;
        }

        $plan = $this->sameTablesCopy->plan($competitionId, $round->id->toString());

        return OfficialResultsApi::json([
            'sourceSeated' => $plan['sourceSeated'],
            'targets' => $plan['targets'],
        ]);
    }
}
