<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The "How long does a {N}-piece puzzle take?" guide family: which sizes have a
 * live guide right now and where it lives.
 *
 * A size gets its own page only while the community has logged enough solo
 * solves for the answer to hold up; below that its URL answers 404, so nothing
 * may link to it either - the guides, the guides index and the sitemap all ask
 * here instead of hardcoding the list.
 */
readonly final class PuzzleTimeGuides
{
    /**
     * The first guide of the family; it keeps its own URL and is always live.
     */
    public const int ORIGINAL_GUIDE_PIECES = 1000;

    /**
     * Sizes served by the generic guide route - its requirement lists exactly these.
     *
     * @var list<int>
     */
    public const array GENERIC_GUIDE_PIECES = [100, 200, 300, 500, 1500, 2000];

    public const string GENERIC_GUIDE_PIECES_REQUIREMENT = '100|200|300|500|1500|2000';

    /**
     * Below this many recorded solo solves a size has no guide (its URL is a 404).
     */
    public const int MINIMUM_SOLO_SOLVES = 300;

    /**
     * A pair or team median is published for a size only from this many group solves.
     */
    public const int MINIMUM_GROUP_SOLVES = 100;

    public function __construct(
        private SolveTimeDistributionProvider $solveTimeDistributionProvider,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function isLive(int $pieces): bool
    {
        return in_array($pieces, $this->livePieces(), true);
    }

    /**
     * @return array<int, string> Path of every live size guide, keyed by pieces count, smallest first
     */
    public function paths(): array
    {
        return $this->urls(UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    /**
     * @return array<int, string> Absolute URL of every live size guide, keyed by pieces count, smallest first
     */
    public function absoluteUrls(): array
    {
        return $this->urls(UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * @return array<int, string>
     */
    private function urls(int $referenceType): array
    {
        $urls = [];

        foreach ($this->livePieces() as $pieces) {
            $urls[$pieces] = $pieces === self::ORIGINAL_GUIDE_PIECES
                ? $this->urlGenerator->generate('guide_puzzle_time_by_pieces', [], $referenceType)
                : $this->urlGenerator->generate('guide_puzzle_time_for_pieces', ['pieces' => $pieces], $referenceType);
        }

        return $urls;
    }

    /**
     * @return list<int> Smallest first; the original guide is always among them
     */
    private function livePieces(): array
    {
        $solo = $this->solveTimeDistributionProvider->snapshot();

        $pieces = array_filter(
            self::GENERIC_GUIDE_PIECES,
            static fn (int $pieces): bool => $solo->withAtLeast($pieces, self::MINIMUM_SOLO_SOLVES) !== null,
        );
        $pieces[] = self::ORIGINAL_GUIDE_PIECES;
        sort($pieces);

        return $pieces;
    }
}
