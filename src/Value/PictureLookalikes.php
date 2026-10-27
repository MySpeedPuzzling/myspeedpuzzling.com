<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How alike a puzzle's pieces look, from the image pipeline's analyses of its current picture (data-mining F8: look-alike
 * pieces -> players disagree more about the puzzle): at the puzzle's real piece count, `traits` pieceLevel.real
 * pieceLookalikeShare / pieceDistinctiveness and the picture's colorRegions, `piece_scale` real.nnShareBelow006 /
 * logCandidates006. What the candidate's window reads at k = 0 (PictureDispersionModel) - never a shown input.
 */
readonly final class PictureLookalikes
{
    /** The numbers in this order - PictureDispersionModel's coefficients follow it */
    public const array NAMES = ['lookalike_share', 'distinctiveness', 'log_colour_regions', 'neighbours_below_006', 'log_candidates_006'];

    /**
     * The SQL columns of these numbers over the `traits` (alias trt) and `piece_scale` (alias psc) analyses of the
     * current picture (JOINS), in the order fromColumns() takes them.
     */
    public const string COLUMNS = "
        trt.result->'pieceLevel'->'real'->>'pieceLookalikeShare' AS lookalike_share,
        trt.result->'pieceLevel'->'real'->>'pieceDistinctiveness' AS lookalike_distinctiveness,
        trt.result->>'colorRegions' AS lookalike_colour_regions,
        psc.result->'real'->>'nnShareBelow006' AS lookalike_below_006,
        psc.result->'real'->>'logCandidates006' AS lookalike_candidates_006
    ";

    /**
     * The two analyses of the picture the estimate `img` is of (StatisticalInputsSql::imageEstimateJoins() - its
     * fingerprint, never a secret puzzle): one unique-index step each.
     */
    public const string JOINS = "
        LEFT JOIN puzzle_image_analysis trt ON trt.puzzle_id = img.puzzle_id AND trt.analyzer = 'traits' AND trt.image_fingerprint = img.image_fingerprint
        LEFT JOIN puzzle_image_analysis psc ON psc.puzzle_id = img.puzzle_id AND psc.analyzer = 'piece_scale' AND psc.image_fingerprint = img.image_fingerprint
    ";

    /**
     * @param list<float> $values in the order of NAMES
     */
    public function __construct(
        public array $values,
    ) {
    }

    /**
     * From the analyses' JSON fields (strings or numbers) - null unless every number is there.
     */
    public static function fromColumns(mixed $lookalikeShare, mixed $distinctiveness, mixed $colourRegions, mixed $below006, mixed $candidates006): null|self
    {
        $values = [];

        foreach ([$lookalikeShare, $distinctiveness, $colourRegions, $below006, $candidates006] as $value) {
            if (!is_numeric($value)) {
                return null;
            }

            $values[] = (float) $value;
        }

        $values[2] = log(1.0 + max(0.0, $values[2]));

        return new self($values);
    }

    /**
     * @param array<string, mixed> $row with COLUMNS
     */
    public static function fromRow(array $row): null|self
    {
        return self::fromColumns($row['lookalike_share'] ?? null, $row['lookalike_distinctiveness'] ?? null, $row['lookalike_colour_regions'] ?? null, $row['lookalike_below_006'] ?? null, $row['lookalike_candidates_006'] ?? null);
    }
}
