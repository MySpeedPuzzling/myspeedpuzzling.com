<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DifficultyTier;

/**
 * One puzzle of a public difficulty ranking. The difficulty itself is
 * members-only: the public list is built from withoutDifficulty() copies, so
 * a template can never print a score or tier for a guest by mistake.
 */
readonly final class DifficultyRankingEntry
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleImage,
        public null|float $puzzleImageRatio,
        public int $piecesCount,
        public string $manufacturerName,
        public int $soloSolvesCount,
        public null|int $medianTimeSolo,
        public null|float $difficultyScore,
        public null|DifficultyTier $difficultyTier,
    ) {
    }

    /**
     * @param array{
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     puzzle_image: null|string,
     *     puzzle_image_ratio: null|float|string,
     *     pieces_count: int|string,
     *     manufacturer_name: string,
     *     solo_solves_count: null|int|string,
     *     median_time_solo: null|int|string,
     *     difficulty_score: float|string,
     *     difficulty_tier: null|int|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $score = (float) $row['difficulty_score'];

        return new self(
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleImage: $row['puzzle_image'],
            puzzleImageRatio: $row['puzzle_image_ratio'] !== null ? (float) $row['puzzle_image_ratio'] : null,
            piecesCount: (int) $row['pieces_count'],
            manufacturerName: $row['manufacturer_name'],
            soloSolvesCount: (int) ($row['solo_solves_count'] ?? 0),
            medianTimeSolo: $row['median_time_solo'] !== null ? (int) $row['median_time_solo'] : null,
            difficultyScore: $score,
            difficultyTier: DifficultyTier::tryFrom((int) $row['difficulty_tier']) ?? DifficultyTier::fromScore($score),
        );
    }

    public function withoutDifficulty(): self
    {
        return new self(
            puzzleId: $this->puzzleId,
            puzzleName: $this->puzzleName,
            puzzleImage: $this->puzzleImage,
            puzzleImageRatio: $this->puzzleImageRatio,
            piecesCount: $this->piecesCount,
            manufacturerName: $this->manufacturerName,
            soloSolvesCount: $this->soloSolvesCount,
            medianTimeSolo: $this->medianTimeSolo,
            difficultyScore: null,
            difficultyTier: null,
        );
    }
}
