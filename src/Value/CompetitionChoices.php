<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Closure;

/**
 * The "Competition / event" picker payload for TomSelect plus what it accepts
 * (docs/features/events-page/high-frequency-series.md "Validation, submit, prefill"): every offered value (one-time
 * events, series, an included edition) is checked in memory; an edition that was not offered - picked by typing
 * (S1) or from the preview's short list - costs one statement, only when such a value is submitted.
 */
readonly final class CompetitionChoices
{
    /**
     * @param list<array{value: string, text: string, keywords: string, optgroup?: string}> $options
     *        Options in display order - value = CompetitionPick::fieldValue(); an edition carries `optgroup` = series id
     * @param list<array{value: string, label: string, logo?: string}> $optgroups
     *        One per series with an offered edition; `value` = series id
     * @param array<string, true> $values Every option value, for O(1) membership checks
     * @param Closure(string): bool $isSelectableEdition Whether an edition id may be picked (publicly visible)
     */
    public function __construct(
        public array $options,
        public array $optgroups,
        private array $values,
        private null|CompetitionPick $current,
        private Closure $isSelectableEdition,
    ) {
    }

    /**
     * Whether the picker renders this exact value as an option
     */
    public function offers(string $fieldValue): bool
    {
        return isset($this->values[$fieldValue]);
    }

    /**
     * Whether a submitted value may be saved: an offered one-time event or series, the current pick, or a publicly
     * visible edition. A bare uuid of an edition - posted by a form an older release rendered (P2) - counts as that
     * edition.
     */
    public function accepts(CompetitionPick $pick): bool
    {
        if ($this->current !== null && $this->current->id === $pick->id) {
            if ($this->current->equals($pick)) {
                return true;
            }

            if ($pick->kind === CompetitionPickKind::Event && $this->current->kind === CompetitionPickKind::Edition) {
                return true;
            }
        }

        if ($this->offers($pick->fieldValue())) {
            return true;
        }

        return match ($pick->kind) {
            CompetitionPickKind::Series => false,
            CompetitionPickKind::Edition, CompetitionPickKind::Event => $this->offers(CompetitionPick::edition($pick->id)->fieldValue())
                || ($this->isSelectableEdition)($pick->id),
        };
    }
}
