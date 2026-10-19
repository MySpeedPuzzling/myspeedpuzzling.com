<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\EventSubscriber\InternalApiAuditSubscriber;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleDifficultyEstimate;
use SpeedPuzzling\Web\Message\RecordPuzzleDifficultyEstimates;
use SpeedPuzzling\Web\Value\PuzzleDifficultyEstimateInput;
use SpeedPuzzling\Web\Value\PuzzleDifficultyEstimateOutcome;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stores up to 500 picture-based difficulty estimates at once - each the puzzle's current one (docs/features/
 * internal-api.md, "Puzzle difficulty labels and estimates"). Body `{"items": [{puzzleId, model, modelVersion,
 * logDifficulty, logP10, logP90, outOfFold, trainingSize, imageFingerprint, computedAt?}]}`. One transaction; a
 * refused item never fails the others: the answer lists every item's outcome in order - `stored`,
 * `stale_fingerprint`, `hidden`, `unknown_puzzle` or `invalid` (with `errors`). A body that is no such list is a 422.
 * Idempotent: the same batch again stores the same values.
 *
 * The audit log gets the counts per outcome, never the values.
 */
final class RecordPuzzleDifficultyEstimatesController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzle-difficulty-estimates',
        methods: ['PUT'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $body = InternalApiJsonBody::parse($request);
        $items = $body['items'] ?? null;
        $unknownFields = array_values(array_diff(array_map(strval(...), array_keys($body)), ['items']));

        if ($unknownFields !== []) {
            throw new InvalidPuzzleDifficultyEstimate(array_fill_keys($unknownFields, 'is not a field of this endpoint (known: items).'));
        }

        if (is_array($items) === false || array_is_list($items) === false || $items === [] || count($items) > RecordPuzzleDifficultyEstimates::MAX_ITEMS) {
            throw new InvalidPuzzleDifficultyEstimate([
                'items' => sprintf('must be a list of 1 to %d estimates.', RecordPuzzleDifficultyEstimates::MAX_ITEMS),
            ]);
        }

        /** @var array<int, PuzzleDifficultyEstimateInput> $valid */
        $valid = [];
        /** @var array<int, PuzzleDifficultyEstimateOutcome> $outcomes */
        $outcomes = [];

        foreach ($items as $index => $item) {
            try {
                $valid[$index] = PuzzleDifficultyEstimateInput::fromItem($item);
            } catch (InvalidPuzzleDifficultyEstimate $exception) {
                $outcomes[$index] = PuzzleDifficultyEstimateOutcome::invalid($exception->getMessage(), $exception->errors);
            }
        }

        if ($valid !== []) {
            $envelope = $this->messageBus->dispatch(new RecordPuzzleDifficultyEstimates($valid));
            $handled = $envelope->last(HandledStamp::class);
            assert($handled instanceof HandledStamp);

            /** @var array<int, PuzzleDifficultyEstimateOutcome> $handledOutcomes */
            $handledOutcomes = $handled->getResult();
            $outcomes += $handledOutcomes;
        }

        ksort($outcomes);

        $counts = array_fill_keys(PuzzleDifficultyEstimateOutcome::STATUSES, 0);
        $answer = [];

        foreach ($outcomes as $index => $outcome) {
            $counts[$outcome->status]++;
            $item = $items[$index];

            $answer[] = array_filter([
                'index' => $index,
                'puzzleId' => is_array($item) && is_string($item['puzzleId'] ?? null) ? strtolower($item['puzzleId']) : null,
                'status' => $outcome->status,
                'message' => $outcome->message,
                'currentFingerprint' => $outcome->currentFingerprint,
                'errors' => $outcome->errors !== [] ? $outcome->errors : null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        $request->attributes->set(InternalApiAuditSubscriber::SUMMARY_ATTRIBUTE, ['items' => count($items)] + $counts);

        return new JsonResponse([
            'counts' => $counts,
            'items' => $answer,
        ]);
    }
}
