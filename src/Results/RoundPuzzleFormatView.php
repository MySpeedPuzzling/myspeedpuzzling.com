<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * A `pick` round's format for the organiser tools' round state (`round.puzzleFormat` of official_results_round_state,
 * competition toolkit A2 - R-review-and-plan.md §1.1, §1.8, A-round-formats.md §6.1). Built from
 * RoundPuzzlesVisibleNow, so the public rule holds for every viewer: a puzzle still hidden from the public comes by its
 * label and position only, for organisers and referees too. Only the piece count goes to them always (the parity with
 * the round's `piecesCount`). Never built for a `total` round - the key is absent there.
 */
readonly final class RoundPuzzleFormatView implements \JsonSerializable
{
    /**
     * @param list<RoundPuzzleSlot> $slots
     * @param array<string, int> $piecesCounts by round puzzle id
     * @param array<string, string> $imageUrls by round puzzle id - only of puzzles whose picture is out
     */
    public function __construct(
        public int $toSolve,
        public array $slots,
        public array $piecesCounts,
        public array $imageUrls,
    ) {
    }

    /**
     * @return array{mode: 'pick', toSolve: int, puzzles: list<array<string, mixed>>}
     */
    public function jsonSerialize(): array
    {
        return [
            'mode' => 'pick',
            'toSolve' => $this->toSolve,
            'puzzles' => array_map(fn (RoundPuzzleSlot $slot): array => [
                'roundPuzzleId' => $slot->roundPuzzleId,
                'position' => $slot->position,
                'label' => $slot->label,
                'hidden' => $slot->isHidden(),
                'revealsAt' => $slot->revealsAt?->format(DateTimeImmutable::ATOM),
                'puzzleId' => $slot->puzzle?->puzzleId,
                'name' => $slot->puzzle?->puzzleName,
                'brand' => $slot->puzzle?->manufacturerName,
                'imageUrl' => $slot->puzzle !== null ? ($this->imageUrls[$slot->roundPuzzleId] ?? null) : null,
                'piecesCount' => $this->piecesCounts[$slot->roundPuzzleId] ?? null,
            ], $this->slots),
        ];
    }
}
