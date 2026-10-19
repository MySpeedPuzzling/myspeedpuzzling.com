<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a puzzle's picture shows, as the image pipeline's `artwork` analysis classified it - the grouping of the image
 * estimates' intervals (tools/puzzle-vision/puzzle_vision/derive/difficulty_prior.py, KINDS) and of their evaluation
 * and gates (ImageEstimateGate). `unknown` = no `artwork` analysis of the current picture yet, or a kind the pipeline
 * does not group (most `ridge-siglip2-full` estimates: puzzles the crop model could not take) - a kind of its own,
 * never pooled with the others.
 */
enum ImageKind: string
{
    case Flat = 'flat';
    case Box = 'box';
    case Assembled = 'assembled';
    case Other = 'other';
    case Unknown = 'unknown';

    /**
     * The `kind` of an `artwork` result (flat / box_front / box_angled / assembled / other) - the same mapping as the
     * pipeline's, anything else is unknown.
     */
    public static function fromArtworkKind(mixed $kind): self
    {
        return match ($kind) {
            'flat' => self::Flat,
            'box_front', 'box_angled' => self::Box,
            'assembled' => self::Assembled,
            'other' => self::Other,
            default => self::Unknown,
        };
    }
}
