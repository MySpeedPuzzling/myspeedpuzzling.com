<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\PuzzleTagCorrection;
use SpeedPuzzling\Web\Message\CorrectPuzzleTag;
use SpeedPuzzling\Web\Message\RefreshPuzzleTags;
use SpeedPuzzling\Web\Query\GetPuzzleTags;
use SpeedPuzzling\Web\Value\PuzzleTagCorrectionVerdict;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * An admin's or a moderator's word on one tag of one puzzle (docs/features/internal-api.md, "Puzzle tags"): body
 * `{"verdict": "add" | "remove", "note": "…"}` - replaces an earlier correction of the same key, survives every
 * re-run of the analysis. Credited to INTERNAL_API_REVIEWER_PLAYER_ID (empty = 400). The puzzle's tags are rewritten
 * at once; answers them with the corrections. 404 for an unknown puzzle or a hidden picture, 422 for a key that
 * cannot be in the taxonomy.
 */
final class PutPuzzleTagCorrectionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetPuzzleTags $getPuzzleTags,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzles/{puzzleId}/tag-corrections/{tagKey}',
        requirements: ['puzzleId' => FirstTryConflictsController::ID_REQUIREMENT, 'tagKey' => '[a-z0-9_.]{1,100}'],
        methods: ['PUT'],
    )]
    public function __invoke(string $puzzleId, string $tagKey, Request $request): JsonResponse
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException('INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to credit the correction to.');
        }

        $body = InternalApiJsonBody::parse($request);
        $verdict = PuzzleTagCorrectionVerdict::tryFrom(InternalApiJsonBody::optionalString($body, 'verdict') ?? '')
            ?? throw new BadRequestHttpException(sprintf('"verdict" must be one of: %s.', implode(', ', PuzzleTagCorrectionVerdict::values())));

        $note = $body['note'] ?? null;

        if ($note !== null && is_string($note) === false) {
            throw new BadRequestHttpException('"note" must be a string.');
        }

        if (is_string($note) && (mb_strlen(trim($note)) > PuzzleTagCorrection::NOTE_MAX_LENGTH || str_contains($note, "\0"))) {
            throw new BadRequestHttpException(sprintf('"note" can be at most %d characters long, without NUL characters.', PuzzleTagCorrection::NOTE_MAX_LENGTH));
        }

        $this->messageBus->dispatch(new CorrectPuzzleTag(
            puzzleId: $puzzleId,
            tagKey: $tagKey,
            verdict: $verdict,
            note: $note,
            decidedByPlayerId: $this->reviewerPlayerId,
        ));

        // Its own transaction - the writer reads the correction committed above
        $this->messageBus->dispatch(new RefreshPuzzleTags([$puzzleId]));

        return new JsonResponse(PuzzleTagsJson::ofPuzzle($this->getPuzzleTags, $puzzleId));
    }
}
