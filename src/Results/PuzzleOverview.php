<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PuzzleNames;

readonly final class PuzzleOverview
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public PuzzleNames $puzzleAlternativeNames,
        public bool $puzzleApproved,
        public string $manufacturerId,
        public string $manufacturerName,
        public int $piecesCount,
        public int $averageTimeSolo,
        public int $fastestTimeSolo,
        public int $averageTimeDuo,
        public int $fastestTimeDuo,
        public int $averageTimeTeam,
        public int $fastestTimeTeam,
        public int $solvedTimes,
        public null|string $puzzleImage,
        public null|float $puzzleImageRatio,
        public bool $isAvailable,
        public null|string $puzzleEan,
        public null|string $puzzleIdentificationNumber,
        public null|DateTimeImmutable $hideImageUntil = null,
        public null|string $manufacturerSlug = null,
        /** Secret competition puzzle: hidden from every listing until this moment (only GetPuzzleOverview::byId loads it) */
        public null|DateTimeImmutable $hideUntil = null,
        /** The main title's language when it is not English (only GetPuzzleOverview::byId loads it - the puzzle page H1) */
        public null|string $nameLanguage = null,
    ) {
    }

    /**
     * @param array{
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     puzzle_image: null|string,
     *     puzzle_image_ratio: null|string,
     *     puzzle_alternative_names: string,
     *     puzzle_approved: bool,
     *     manufacturer_id: string,
     *     manufacturer_name: string,
     *     manufacturer_slug?: null|string,
     *     pieces_count: int,
     *     average_time_solo: null|string,
     *     fastest_time_solo: null|int,
     *     average_time_duo: null|string,
     *     fastest_time_duo: null|int,
     *     average_time_team: null|string,
     *     fastest_time_team: null|int,
     *     solved_times: int,
     *     is_available: bool,
     *     puzzle_ean: null|string,
     *     puzzle_identification_number: null|string,
     *     hide_image_until: null|string,
     *     hide_until?: null|string,
     *     name_language?: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row, DateTimeImmutable $now): self
    {
        $hideUntil = $row['hide_until'] ?? null;
        $hideImageUntil = $row['hide_image_until'] !== null ? new DateTimeImmutable($row['hide_image_until']) : null;
        // While a competition keeps the picture secret, its EAN and brand code give the box away just the same
        $codesHidden = ($hideImageUntil !== null && $hideImageUntil > $now)
            || ($hideUntil !== null && new DateTimeImmutable($hideUntil) > $now);

        return new self(
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleAlternativeNames: PuzzleNames::fromJson($row['puzzle_alternative_names']),
            puzzleApproved: $row['puzzle_approved'],
            manufacturerId: $row['manufacturer_id'],
            manufacturerName: $row['manufacturer_name'],
            piecesCount: $row['pieces_count'],
            averageTimeSolo: (int) $row['average_time_solo'],
            fastestTimeSolo: (int) $row['fastest_time_solo'],
            averageTimeDuo: (int) $row['average_time_duo'],
            fastestTimeDuo: (int) $row['fastest_time_duo'],
            averageTimeTeam: (int) $row['average_time_team'],
            fastestTimeTeam: (int) $row['fastest_time_team'],
            solvedTimes: $row['solved_times'],
            puzzleImage: $row['puzzle_image'],
            puzzleImageRatio: $row['puzzle_image_ratio'] !== null ? (float) $row['puzzle_image_ratio'] : null,
            isAvailable: $row['is_available'],
            puzzleEan: $codesHidden ? null : $row['puzzle_ean'],
            puzzleIdentificationNumber: $codesHidden ? null : $row['puzzle_identification_number'],
            hideImageUntil: $hideImageUntil,
            manufacturerSlug: $row['manufacturer_slug'] ?? null,
            hideUntil: $hideUntil !== null ? new DateTimeImmutable($hideUntil) : null,
            nameLanguage: $row['name_language'] ?? null,
        );
    }
}
