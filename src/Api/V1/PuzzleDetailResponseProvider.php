<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Services\Api\PuzzleResponseFactory;

/**
 * GET /api/v1/puzzles/{puzzleId} - the puzzle detail is the catalog card of
 * that one puzzle, built by the same PuzzleResponseFactory as GET /v1/puzzles:
 * the membership / scope gates and the three insight objects can never drift
 * between the list and the detail.
 *
 * 404 for an unknown or malformed id, and - stricter than the website's
 * puzzle page - for a secret competition puzzle (hide_until in the future),
 * which no puzzle endpoint of the API ever returns (plan §0, N5).
 *
 * @implements ProviderInterface<PuzzleDetailResponse>
 */
final readonly class PuzzleDetailResponseProvider implements ProviderInterface
{
    public function __construct(
        private GetPuzzleOverview $getPuzzleOverview,
        private PuzzleResponseFactory $puzzleResponseFactory,
        private ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PuzzleDetailResponse
    {
        /** @var string $puzzleId */
        $puzzleId = $uriVariables['puzzleId'];

        // Validated before any query; the overview query repeats the check, the
        // API's 404 for a malformed id is decided here.
        if (Uuid::isValid($puzzleId) === false) {
            throw new PuzzleNotFound();
        }

        // Throws PuzzleNotFound (404) for an unknown id. The image is already
        // null while its embargo (hide_image_until) lasts - the query does that.
        $overview = $this->getPuzzleOverview->byId($puzzleId);

        // GetPuzzleOverview::byId serves the website's puzzle page too and does
        // not filter secret competition puzzles - for the API they do not exist.
        if ($overview->hideUntil !== null && $overview->hideUntil > $this->clock->now()) {
            throw new PuzzleNotFound();
        }

        return PuzzleDetailResponse::fromCard($this->puzzleResponseFactory->card($overview));
    }
}
