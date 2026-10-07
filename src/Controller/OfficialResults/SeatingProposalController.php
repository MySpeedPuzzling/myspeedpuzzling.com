<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OfficialResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\SeatingProposer;
use SpeedPuzzling\Web\Value\SeatingSource;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Auto-assign of the seating page, as a proposal - table numbers for every entrant of the round, nothing written
 * (docs/features/competitions-management/seating.md). The page applies it with `official_results_assign_table_numbers`.
 *
 * Query: `source` (earlier_rounds | msp_times | random | name; none = the best available), `order` (fastest_first |
 * slowest_first), `seed` (random: the draw to repeat; none = a new draw), `first` (the first table number, default 1).
 * 400 `invalid_seating_options` for anything else.
 */
final class SeatingProposalController extends AbstractController
{
    private const int MAX_SEED = 999999;

    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly SeatingProposer $seatingProposer,
        private readonly OfficialResultsApi $api,
    ) {
    }

    #[Route(
        path: '/{_locale}/official-results/rounds/{roundId}/seating-proposal',
        name: 'official_results_seating_proposal',
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

        $sourceParameter = $request->query->getString('source');
        $source = $sourceParameter === '' ? null : SeatingSource::tryFrom($sourceParameter);
        $order = $request->query->getString('order', 'fastest_first');
        $seed = self::positiveInt($request->query->getString('seed'), self::MAX_SEED);
        $first = self::positiveInt($request->query->getString('first', '1'), SeatingProposer::MAX_TABLE_NUMBER);

        if (
            ($sourceParameter !== '' && $source === null)
            || !in_array($order, ['fastest_first', 'slowest_first'], true)
            || ($request->query->has('seed') && $seed === null)
            || $first === null
        ) {
            return OfficialResultsApi::error('invalid_seating_options', JsonResponse::HTTP_BAD_REQUEST);
        }

        $proposal = $this->seatingProposer->propose(
            competitionId: $competitionId,
            roundId: $round->id->toString(),
            source: $source,
            slowestFirst: $order === 'slowest_first',
            randomSeed: $seed ?? random_int(1, self::MAX_SEED),
            firstTable: $first,
            locale: $request->getLocale(),
        );

        // The last table must stay a table number (1..9999)
        if ($proposal->rows !== [] && $first + count($proposal->rows) - 1 > SeatingProposer::MAX_TABLE_NUMBER) {
            return OfficialResultsApi::error('invalid_seating_options', JsonResponse::HTTP_BAD_REQUEST);
        }

        return OfficialResultsApi::json($proposal);
    }

    private static function positiveInt(string $value, int $max): null|int
    {
        if (preg_match('/^\d{1,9}$/', $value) !== 1) {
            return null;
        }

        $number = (int) $value;

        return $number >= 1 && $number <= $max ? $number : null;
    }
}
