<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\ArrangeRoundPuzzles;
use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;
use SpeedPuzzling\Web\Value\RoundPuzzleLabel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The order and labels of a round's puzzles (docs/features/internal-api.md "Round format, labels and Unboxed",
 * spws-wizard.md package D) - the round puzzles page's message, ArrangeRoundPuzzles. Puzzles are named by their puzzle
 * id (the round answer's `id`). Body (at least one):
 * - `puzzleIds`: the round's puzzles in the new order; a puzzle of the round left out keeps its place after them;
 * - `labels`: `{"<puzzleId>": "Challenge 3" | null}` - null or "" = the letter of its place; a puzzle left out keeps its
 *   label; at most RoundPuzzleLabel::MAX_LENGTH characters.
 * A puzzle id that is not a puzzle of the round is a 400 - nothing is written. Answers the round.
 */
final class PutRoundPuzzleArrangementController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly GetAdminCompetitions $getAdminCompetitions,
        private readonly OfficialResultsLiveUpdates $officialResultsLiveUpdates,
        private readonly CompetitionDisplayLiveUpdates $displayLiveUpdates,
    ) {
    }

    #[Route(
        path: '/internal-api/rounds/{roundId}/arrangement',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['PUT'],
    )]
    public function __invoke(string $roundId, Request $request): JsonResponse
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $roundId = $round->id->toString();
        $input = InternalApiInput::fromRequest($request, ['puzzleIds', 'labels']);

        /** @var array<string, string> $roundPuzzleIds puzzle id => its round puzzle id */
        $roundPuzzleIds = [];
        foreach ($round->roundPuzzles as $roundPuzzle) {
            $roundPuzzleIds[$roundPuzzle->puzzle->id->toString()] = $roundPuzzle->id->toString();
        }

        if ($input->has('puzzleIds') === false && $input->has('labels') === false) {
            $input->addError('body', 'send "puzzleIds" (the order), "labels" or both.');
        }

        $order = [];
        $sentIds = $request->toArray()['puzzleIds'] ?? null;
        $orderIds = $input->idList('puzzleIds') ?? [];

        // idList() drops a repeated id - a list naming a puzzle twice is a mistake, said
        if (is_array($sentIds) && count($sentIds) !== count($orderIds) && $input->hasError('puzzleIds') === false) {
            $input->addError('puzzleIds', 'names a puzzle more than once.');
        }

        foreach ($orderIds as $index => $puzzleId) {
            if (isset($roundPuzzleIds[$puzzleId]) === false) {
                $input->addError(sprintf('puzzleIds[%d]', $index), 'is not a puzzle of this round.');

                continue;
            }

            $order[] = $roundPuzzleIds[$puzzleId];
        }

        $labels = [];
        $sentLabels = $request->toArray()['labels'] ?? null;

        if ($input->has('labels') && (is_array($sentLabels) === false || ($sentLabels !== [] && array_is_list($sentLabels)))) {
            $input->addError('labels', 'must be an object {"<puzzleId>": "label" | null}.');
        } elseif (is_array($sentLabels)) {
            foreach ($sentLabels as $puzzleId => $label) {
                $puzzleId = strtolower((string) $puzzleId);

                if (isset($roundPuzzleIds[$puzzleId]) === false) {
                    $input->addError(sprintf('labels.%s', $puzzleId), 'is not a puzzle of this round.');
                } elseif ($label !== null && is_string($label) === false) {
                    $input->addError(sprintf('labels.%s', $puzzleId), 'must be a string or null.');
                } elseif (self::labelFits($label) === false) {
                    $input->addError(sprintf('labels.%s', $puzzleId), sprintf('can be at most %d characters long.', RoundPuzzleLabel::MAX_LENGTH));
                } else {
                    $labels[$roundPuzzleIds[$puzzleId]] = $label;
                }
            }
        }

        $input->throwIfInvalid();

        $this->messageBus->dispatch(new ArrangeRoundPuzzles(
            roundId: $roundId,
            competitionId: $round->competition->id->toString(),
            roundPuzzleIds: $order,
            labels: $labels,
        ));

        // After the commit - the round puzzles page's signals: judges of a `pick` round read its labels again, displays
        // show the labels and order (the reveal push signals them too)
        if ($round->puzzleFormat === RoundPuzzleFormat::Pick) {
            $this->officialResultsLiveUpdates->puzzlesRevealed($roundId);
        } else {
            $this->displayLiveUpdates->puzzlesChanged($roundId);
        }

        return new JsonResponse($this->getAdminCompetitions->round($roundId)->toArray());
    }

    /**
     * The handler's own rule (RoundPuzzleLabel::normalise() refuses a label too long) - checked before anything is written
     */
    private static function labelFits(null|string $label): bool
    {
        try {
            RoundPuzzleLabel::normalise($label);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }
}
