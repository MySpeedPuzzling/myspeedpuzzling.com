<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Controller\PiecesPuzzlesController;
use SpeedPuzzling\Web\Value\PiecesRange;

/**
 * Where a puzzle sits in the catalogue - the pages its breadcrumb, its piece
 * count and its related-puzzles module link to (docs/features/seo/
 * implementation-plan-2026-10.md, WS-F2):
 *
 * - the brand hub, when the brand has a slug;
 * - for the piece count, the brand × pieces page when that one is indexable,
 *   otherwise the pieces hub of all brands when the count has one, otherwise
 *   nothing (no link to a noindexed or missing page).
 */
readonly final class PuzzleCatalogueLinks
{
    public function __construct(
        public null|string $brandSlug,
        public int $piecesCount,
        public bool $brandPiecesPageIndexable,
        public bool $piecesHubExists,
    ) {
    }

    /**
     * @param null|BrandHubStats $brandHub The brand's cached hub stats - null when the brand has no slug
     */
    public static function forPuzzle(PuzzleOverview $puzzle, null|BrandHubStats $brandHub): self
    {
        // The pages of the puzzle's piece-count class: a 504-piece box links the 500 pages
        $piecesCount = PiecesRange::classOf($puzzle->piecesCount);

        return new self(
            brandSlug: $puzzle->manufacturerSlug,
            piecesCount: $piecesCount,
            brandPiecesPageIndexable: $puzzle->manufacturerSlug !== null
                && $brandHub?->hasIndexablePiecesPage($piecesCount) === true,
            piecesHubExists: in_array($piecesCount, PiecesPuzzlesController::ALLOWED_PIECES, true),
        );
    }

    public function linksBrandHub(): bool
    {
        return $this->brandSlug !== null;
    }

    public function linksBrandPiecesPage(): bool
    {
        return $this->brandSlug !== null && $this->brandPiecesPageIndexable;
    }

    public function linksPiecesHub(): bool
    {
        return $this->linksBrandPiecesPage() === false && $this->piecesHubExists;
    }
}
