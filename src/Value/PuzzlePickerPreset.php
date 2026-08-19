<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One-tap presets above the picker card. A preset is nothing but a set of
 * query parameters that fills the filter form - no backend concept beyond
 * this list. "Beat my record" needs my predictions (members without the
 * time-predictions opt-out); everything else is built from free filters.
 */
enum PuzzlePickerPreset: string
{
    case SurpriseMe = 'surprise_me';
    case SomethingNew = 'something_new';
    case QuickOne = 'quick_one';
    case DustOffTheShelf = 'dust_off';
    case RatingGrind = 'rating_grind';
    case BeatMyRecord = 'beat_my_record';

    /**
     * @return array<string, mixed>
     */
    public function queryParams(): array
    {
        return match ($this) {
            self::SurpriseMe => ['source' => PuzzlePickerSource::Mine->value],
            self::SomethingNew => ['source' => PuzzlePickerSource::Mine->value, 'solved' => PuzzlePickerSolved::Never->value],
            self::QuickOne => ['predicted_max' => '60'],
            self::DustOffTheShelf => ['since' => '6', 'since_unit' => PuzzlePickerSinceUnit::Month->value, 'since_require_solved' => '1'],
            self::RatingGrind => ['pieces' => ['500'], 'solved' => PuzzlePickerSolved::Never->value, 'community' => PuzzlePickerCommunity::Rated->value],
            self::BeatMyRecord => ['solved' => PuzzlePickerSolved::Before->value, 'gap' => PuzzlePickerGap::Slower->value, 'order' => PuzzlePickerOrder::GapSlower->value],
        };
    }

    public function requiresPredictions(): bool
    {
        return $this === self::BeatMyRecord;
    }

    /**
     * Bootstrap icon class of the chip.
     */
    public function icon(): string
    {
        return match ($this) {
            self::SurpriseMe => 'bi-shuffle',
            self::SomethingNew => 'bi-stars',
            self::QuickOne => 'bi-lightning-charge',
            self::DustOffTheShelf => 'bi-hourglass-split',
            self::RatingGrind => 'bi-graph-up-arrow',
            self::BeatMyRecord => 'bi-trophy',
        };
    }
}
