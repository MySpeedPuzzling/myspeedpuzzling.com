<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OfficialResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\InvalidTableLabels;
use SpeedPuzzling\Web\Exceptions\TableLabelsRefused;
use SpeedPuzzling\Web\Message\SetRoundTableLabels;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Results\RoundResultsOverview;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;
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
 * The seating page's "Table names" (table-labels-and-plates.md): `{"rounds": ["<id>", …], "labels": ["A1", …] | null,
 * "expected": {"<id>": [...] | null}}` → SetRoundTableLabels (this round must be one of them) → `rounds` (each round's
 * overview, with `tableLabels`). 422 `invalid_labels` / `labels_refused` with `problems` (+ `message`) - nothing saved.
 */
final class SetRoundTableLabelsController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly GetRoundResultsOverview $getRoundResultsOverview,
        private readonly OfficialResultsApi $api,
        private readonly OfficialResultsLiveUpdates $liveUpdates,
        private readonly CompetitionDisplayLiveUpdates $displayLiveUpdates,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/official-results/rounds/{roundId}/table-labels',
        name: 'official_results_table_labels',
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

        $roundIds = self::roundIds($body['rounds'] ?? null);
        $labels = $body['labels'] ?? null;
        $expected = self::expected($body['expected'] ?? null);

        if ($roundIds === null || !in_array($round->id->toString(), $roundIds, true) || $expected === null || ($labels !== null && !self::isStringList($labels))) {
            return OfficialResultsApi::error('invalid_table_labels', JsonResponse::HTTP_BAD_REQUEST);
        }

        /** @var null|list<string> $labels */
        try {
            $envelope = $this->messageBus->dispatch(new SetRoundTableLabels($competitionId, $roundIds, $labels, $expected));
        } catch (InvalidTableLabels $invalid) {
            return OfficialResultsApi::error('invalid_labels', JsonResponse::HTTP_UNPROCESSABLE_ENTITY, [
                'problems' => array_map(fn (array $problem): array => [
                    ...$problem,
                    'message' => $this->translator->trans('seating.labels.reason.' . $problem['reason'], [
                        '%label%' => $problem['label'],
                        '%first%' => $problem['first'] ?? '',
                    ]),
                ], $invalid->problems),
            ]);
        } catch (TableLabelsRefused $refused) {
            return OfficialResultsApi::error('labels_refused', JsonResponse::HTTP_UNPROCESSABLE_ENTITY, [
                'problems' => array_map(fn (array $problem): array => [
                    ...$problem,
                    'message' => $this->translator->trans('seating.labels.reason.' . $problem['reason'], [
                        '%highest%' => $problem['highest'] ?? 0,
                    ]),
                ], $refused->problems),
            ]);
        } catch (CompetitionRoundNotFound) {
            return OfficialResultsApi::error('invalid_table_labels', JsonResponse::HTTP_BAD_REQUEST);
        }

        /** @var list<string> $changed */
        $changed = $envelope->last(HandledStamp::class)?->getResult() ?? [];

        foreach ($changed as $changedRoundId) {
            $this->liveUpdates->roundChanged($changedRoundId);
            $this->displayLiveUpdates->roundChanged($changedRoundId);
        }

        $overviews = array_values(array_filter(
            $this->getRoundResultsOverview->forCompetition($competitionId),
            static fn (RoundResultsOverview $overview): bool => in_array($overview->roundId, $roundIds, true),
        ));

        return OfficialResultsApi::json(['changed' => $changed, 'rounds' => $overviews]);
    }

    /**
     * @return null|list<string>
     */
    private static function roundIds(mixed $value): null|array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 100) {
            return null;
        }

        $ids = [];

        foreach ($value as $id) {
            if (!is_string($id) || preg_match('/^' . FirstTryConflictsController::ID_REQUIREMENT . '$/', $id) !== 1) {
                return null;
            }

            $ids[strtolower($id)] = true;
        }

        return array_keys($ids);
    }

    /**
     * @return null|array<string, null|list<string>>
     */
    private static function expected(mixed $value): null|array
    {
        if (!is_array($value)) {
            return null;
        }

        $expected = [];

        foreach ($value as $id => $list) {
            if (!is_string($id) || ($list !== null && !self::isStringList($list))) {
                return null;
            }

            /** @var null|list<string> $list */
            $expected[strtolower($id)] = $list;
        }

        return $expected;
    }

    private static function isStringList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && count($value) <= 10000 && array_all($value, static fn (mixed $item): bool => is_string($item));
    }
}
