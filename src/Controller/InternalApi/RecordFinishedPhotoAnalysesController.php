<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\EventSubscriber\InternalApiAuditSubscriber;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleImageAnalysis;
use SpeedPuzzling\Web\Message\RecordFinishedPhotoAnalyses;
use SpeedPuzzling\Web\Value\FinishedPhotoAnalysisInput;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisOutcome;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stores up to 200 analyses of players' finished-puzzle photos at once (docs/features/internal-api.md, "Finished-puzzle
 * photos") - body `{"items": [{puzzleId, analyzer, analyzerVersion, photoFingerprint, imageFingerprint, analyzedAt?,
 * result}]}`. One transaction; a refused item never fails the others: the answer lists every item's outcome in order -
 * `stored`, `stale_fingerprint` (another catalogue picture now - `currentFingerprint` - or the photo is gone),
 * `not_found` or `invalid` (with `errors`). A body that is no such list at all is a 422. The audit log gets the counts
 * per outcome, never the payloads.
 */
final class RecordFinishedPhotoAnalysesController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/internal-api/finished-photo-analyses',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $body = InternalApiJsonBody::parse($request);
        $items = $body['items'] ?? null;
        $unknownFields = array_values(array_diff(array_map(strval(...), array_keys($body)), ['items']));

        if ($unknownFields !== []) {
            throw new InvalidPuzzleImageAnalysis(array_fill_keys($unknownFields, 'is not a field of this endpoint (known: items).'));
        }

        if (is_array($items) === false || array_is_list($items) === false || $items === [] || count($items) > RecordFinishedPhotoAnalyses::MAX_ITEMS) {
            throw new InvalidPuzzleImageAnalysis([
                'items' => sprintf('must be a list of 1 to %d analyses.', RecordFinishedPhotoAnalyses::MAX_ITEMS),
            ]);
        }

        /** @var array<int, FinishedPhotoAnalysisInput> $valid */
        $valid = [];
        /** @var array<int, PuzzleImageAnalysisOutcome> $outcomes */
        $outcomes = [];

        foreach ($items as $index => $item) {
            try {
                $valid[$index] = FinishedPhotoAnalysisInput::fromItem($item);
            } catch (InvalidPuzzleImageAnalysis $exception) {
                $outcomes[$index] = PuzzleImageAnalysisOutcome::invalid($exception->getMessage(), $exception->errors);
            }
        }

        if ($valid !== []) {
            $envelope = $this->messageBus->dispatch(new RecordFinishedPhotoAnalyses($valid));
            $handled = $envelope->last(HandledStamp::class);
            assert($handled instanceof HandledStamp);

            /** @var array<int, PuzzleImageAnalysisOutcome> $handledOutcomes */
            $handledOutcomes = $handled->getResult();
            $outcomes += $handledOutcomes;
        }

        ksort($outcomes);

        $counts = array_fill_keys([
            PuzzleImageAnalysisOutcome::STORED,
            PuzzleImageAnalysisOutcome::STALE_FINGERPRINT,
            PuzzleImageAnalysisOutcome::NOT_FOUND,
            PuzzleImageAnalysisOutcome::INVALID,
        ], 0);
        $answer = [];

        foreach ($outcomes as $index => $outcome) {
            $counts[$outcome->status]++;
            $item = $items[$index];

            $answer[] = array_filter([
                'index' => $index,
                'puzzleId' => is_array($item) && is_string($item['puzzleId'] ?? null) ? strtolower($item['puzzleId']) : null,
                'photoFingerprint' => is_array($item) && is_string($item['photoFingerprint'] ?? null) ? $item['photoFingerprint'] : null,
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
