<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\AttemptFlags;

/**
 * The parts of the candidate model (v5) that can be switched off - each was kept only because a time-split
 * measurement confirmed it (README "Step 8", "The candidate model (v4)"); evaluate-predictions --candidate-off=…
 * measures the model without any of them. All on by default.
 */
readonly final class CandidateFeatures
{
    /** Proposal keys of the review (docs/features/puzzle-intelligence/README.md) => what they switch */
    public const array NAMES = [
        'drift_index' => 'P6: index = time ÷ (pace × e^drift of the solver)',
        'precision_weights' => 'P6: each index weighs 1 ÷ its noise variance (else all alike)',
        'posterior_all_k' => 'P6: one posterior for every k (else the median from 5 indices, like v3)',
        'prior_types' => 'P1: a variance per prior type (else one for all)',
        'image_prior' => 'P1: the image estimate as the first prior (else brand × pieces)',
        'centre_offsets' => 'P3: centre offsets per size × pace source × k',
        'pace_terms' => 'P3: window variance for interpolated / 500-scaled paces',
        'hygiene' => 'P5: unflagged / after-own-group indices weigh less',
        'comment' => 'F6: indices of results with a comment weigh less',
        'puzzle_weighted_brand' => 'P8: brand × pieces prior over puzzles (else over indices)',
        'competition' => 'F6: competition window scale',
        'unboxed' => 'F6: unboxed offset and window scale',
        'dispersion' => 'F8: the puzzle\'s dispersion in the window',
        'source_quantiles' => 'F4: the prior type in the window quantile keys',
        'repeat_gap' => 'P4: the gap since the last solve in the repeat window',
        'size_curve' => 'Size curve: the pace from every size the player has, pooled through the population curve',
        'taste' => 'P7: the player\'s taste - b_i + u_i · W x_j over the picture factors, kind and size (TasteModel)',
        'competition_player' => 'F7: the player\'s own shrunk offset at events (with the taste)',
        'image_interval' => 'v6: the image prior\'s own variance from the estimate\'s 80 % interval (else one variance for every estimate)',
        'picture_dispersion' => 'v6 (F8): the k = 0 window from how alike the picture\'s pieces look (traits + piece_scale)',
        'unboxed_variance' => 'v6: the unboxed window as a variance of its own (else a scale of the boxed one)',
        'adjusted_pace' => 'v7 (P9): the pace and drift on the average puzzle - the median of ln seconds - log difficulty, estimated jointly with the puzzles\' difficulty (AdjustedPace)',
        'player_slope' => 'v7 (F7): the player\'s own difficulty slope - γ (d - their mean d) from their residuals after the taste (TasteState::slopeTerm())',
    ];

    /** Index-noise terms of the flags (CandidateModelParameters::indexVariance()) and the feature that switches each */
    public const array FLAG_TERMS = [
        'unflagged' => AttemptFlags::UNFLAGGED,
        'after_group' => AttemptFlags::AFTER_GROUP,
        'commented' => AttemptFlags::COMMENTED,
    ];

    public const array FLAG_FEATURES = [
        'unflagged' => 'hygiene',
        'after_group' => 'hygiene',
        'commented' => 'comment',
    ];

    /**
     * @param array<string, bool> $switches
     */
    private function __construct(
        private array $switches,
    ) {
    }

    /**
     * The parts the time-split measurement did not confirm (README "Step 8", "The candidate model (v4)") - off in the
     * fitted candidate, still measurable with evaluate-predictions --candidate-off / --ablation.
     */
    public const array NOT_KEPT = ['hygiene', 'comment', 'source_quantiles'];

    public static function all(): self
    {
        return new self(array_fill_keys(array_keys(self::NAMES), true));
    }

    /**
     * What the daily fit uses: every part but NOT_KEPT.
     */
    public static function kept(): self
    {
        return self::without(self::NOT_KEPT);
    }

    /**
     * @param list<string> $off
     */
    public static function without(array $off): self
    {
        $switches = array_fill_keys(array_keys(self::NAMES), true);

        foreach ($off as $name) {
            if (!isset(self::NAMES[$name])) {
                throw new \InvalidArgumentException(sprintf('Unknown candidate feature "%s" (%s)', $name, implode(', ', array_keys(self::NAMES))));
            }

            $switches[$name] = false;
        }

        return new self($switches);
    }

    /**
     * @param array<string, bool> $stored
     */
    public static function fromArray(array $stored): self
    {
        $switches = array_fill_keys(array_keys(self::NAMES), true);

        foreach ($stored as $name => $on) {
            if (isset(self::NAMES[$name])) {
                $switches[$name] = $on;
            }
        }

        return new self($switches);
    }

    public function on(string $name): bool
    {
        return $this->switches[$name] ?? false;
    }

    /**
     * The flags whose index-noise terms count (AttemptFlags bits), the others masked out.
     */
    public function flagMask(): int
    {
        $mask = 0;

        foreach (self::FLAG_TERMS as $term => $flag) {
            if ($this->on(self::FLAG_FEATURES[$term])) {
                $mask |= $flag;
            }
        }

        return $mask;
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return $this->switches;
    }
}
