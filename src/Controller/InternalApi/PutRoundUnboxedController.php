<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\ChangeRoundUnboxed;
use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A round's "Unboxed" (docs/features/internal-api.md "Round format, labels and Unboxed", world-series-rules.md §7,
 * spws-unboxed-per-puzzle.md) - the round puzzles page's message, ChangeRoundUnboxed. Body:
 * - `unboxed` (required): true = every puzzle of the round (the per-puzzle marks are cleared, like the page does);
 * - `unboxedPuzzleIds` (with `unboxed: false` only): exactly these puzzles of the round are unboxed on their own, the
 *   others not; left out = their marks stay as they are;
 * - `confirmReveal`: a change that would let a box picture out before the round's results are first published (a
 *   puzzle unboxed now would not be) is refused - 409 `pictures_would_show` with the puzzles - unless true (the page's
 *   confirm).
 * Answers the round (`unboxed` on it and on each puzzle).
 */
final class PutRoundUnboxedController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly GetAdminCompetitions $getAdminCompetitions,
        private readonly OfficialResultsLiveUpdates $officialResultsLiveUpdates,
    ) {
    }

    #[Route(
        path: '/internal-api/rounds/{roundId}/unboxed',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['PUT'],
    )]
    public function __invoke(string $roundId, Request $request): JsonResponse
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $roundId = $round->id->toString();
        $input = InternalApiInput::fromRequest($request, ['unboxed', 'unboxedPuzzleIds', 'confirmReveal']);

        $unboxed = $input->bool('unboxed');
        if ($unboxed === null && $input->hasError('unboxed') === false) {
            $input->addError('unboxed', 'is required: true = every puzzle of the round, false = none or only "unboxedPuzzleIds".');
        }

        $confirmReveal = $input->bool('confirmReveal') ?? false;
        $puzzleIds = $input->idList('unboxedPuzzleIds');

        if ($unboxed === true && $puzzleIds !== null && $puzzleIds !== []) {
            $input->addError('unboxedPuzzleIds', 'is only for "unboxed": false - with true every puzzle of the round is unboxed.');
        }

        /** @var array<string, string> $roundPuzzleIds puzzle id => its round puzzle id */
        $roundPuzzleIds = [];
        /** @var array<string, string> $names puzzle id => its name */
        $names = [];
        /** @var array<string, bool> $unboxedNow puzzle id => unboxed now (the round or its own mark) */
        $unboxedNow = [];
        /** @var array<string, bool> $ownMarks puzzle id => its own mark */
        $ownMarks = [];
        foreach ($round->roundPuzzles as $roundPuzzle) {
            $puzzleId = $roundPuzzle->puzzle->id->toString();
            $roundPuzzleIds[$puzzleId] = $roundPuzzle->id->toString();
            $names[$puzzleId] = $roundPuzzle->puzzle->name;
            $unboxedNow[$puzzleId] = $roundPuzzle->isUnboxed();
            $ownMarks[$puzzleId] = $roundPuzzle->unboxed;
        }

        $listed = [];
        foreach ($puzzleIds ?? [] as $index => $puzzleId) {
            if (isset($roundPuzzleIds[$puzzleId]) === false) {
                $input->addError(sprintf('unboxedPuzzleIds[%d]', $index), 'is not a puzzle of this round.');

                continue;
            }

            $listed[] = $puzzleId;
        }

        $input->throwIfInvalid();
        assert($unboxed !== null);

        // The marks to write: the round switch clears them (the page's rule); null = kept
        $marks = match (true) {
            $unboxed => [],
            $puzzleIds !== null => $listed,
            default => null,
        };

        // A held picture coming out early needs a yes (before the first publish only - after it every picture is out)
        if ($round->resultsFirstPublishedAt === null && $confirmReveal === false) {
            $released = [];

            foreach ($unboxedNow as $puzzleId => $wasUnboxed) {
                $willBeUnboxed = $unboxed
                    || ($marks !== null ? in_array($puzzleId, $marks, true) : $ownMarks[$puzzleId]);

                if ($wasUnboxed && $willBeUnboxed === false) {
                    $released[] = ['puzzleId' => $puzzleId, 'name' => $names[$puzzleId]];
                }
            }

            if ($released !== []) {
                return new JsonResponse([
                    'error' => 'pictures_would_show',
                    'message' => 'These puzzles\' box pictures would show before the round\'s results are published. Send "confirmReveal": true to go ahead.',
                    'puzzles' => $released,
                ], JsonResponse::HTTP_CONFLICT);
            }
        }

        $this->messageBus->dispatch(new ChangeRoundUnboxed(
            competitionId: $round->competition->id->toString(),
            roundId: $roundId,
            unboxed: $unboxed,
            unboxedRoundPuzzleIds: $marks !== null ? array_map(static fn (string $puzzleId): string => $roundPuzzleIds[$puzzleId], $marks) : null,
        ));

        // After the commit: open judge pages and the event's displays read the round's puzzles again (the page's push)
        $this->officialResultsLiveUpdates->puzzlesRevealed($roundId);

        return new JsonResponse($this->getAdminCompetitions->round($roundId)->toArray());
    }
}
