<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetRoundPuzzlePiecesCounts;
use SpeedPuzzling\Web\Query\RoundPuzzlesVisibleNow;
use SpeedPuzzling\Web\Results\RoundPuzzleFormatView;
use SpeedPuzzling\Web\Results\RoundResultsOverview;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;

/**
 * Gives the `pick` rounds of the organiser tools' round state their `puzzleFormat` (competition toolkit A2,
 * R-review-and-plan.md §1.8): the slots of RoundPuzzlesVisibleNow (the public rule for every viewer) plus the piece
 * counts. A competition without a `pick` round costs no statement and its rounds come back as they were - the JSON
 * stays the one existing consumers read (RoundResultsStateGoldenTest).
 */
readonly final class RoundPuzzleFormats
{
    public function __construct(
        private RoundPuzzlesVisibleNow $roundPuzzlesVisibleNow,
        private GetRoundPuzzlePiecesCounts $getRoundPuzzlePiecesCounts,
        private ImageThumbnailTwigExtension $imageThumbnail,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<RoundResultsOverview> $rounds
     * @return list<RoundResultsOverview> the same rounds, `pick` ones with their format attached
     */
    public function attachTo(array $rounds): array
    {
        $pickRoundIds = [];

        foreach ($rounds as $round) {
            if ($round->puzzleFormat === RoundPuzzleFormat::Pick->value && $round->puzzlesToSolve !== null) {
                $pickRoundIds[] = $round->roundId;
            }
        }

        if ($pickRoundIds === []) {
            return $rounds;
        }

        $puzzlesNow = $this->roundPuzzlesVisibleNow->forRounds($pickRoundIds, $this->clock->now());
        $piecesCounts = $this->getRoundPuzzlePiecesCounts->ofRounds($pickRoundIds);

        return array_map(function (RoundResultsOverview $round) use ($puzzlesNow, $piecesCounts): RoundResultsOverview {
            $now = $puzzlesNow[$round->roundId] ?? null;

            if ($now === null || $round->puzzlesToSolve === null) {
                return $round;
            }

            $imageUrls = [];
            foreach ($now->slots as $slot) {
                if ($slot->puzzle?->puzzleImage !== null) {
                    $imageUrls[$slot->roundPuzzleId] = $this->imageThumbnail->thumbnailUrl($slot->puzzle->puzzleImage, 'puzzle_small');
                }
            }

            return $round->withPuzzleFormat(new RoundPuzzleFormatView(
                toSolve: $round->puzzlesToSolve,
                slots: $now->slots,
                piecesCounts: array_intersect_key($piecesCounts, array_flip(array_map(static fn ($slot): string => $slot->roundPuzzleId, $now->slots))),
                imageUrls: $imageUrls,
            ));
        }, $rounds);
    }
}
