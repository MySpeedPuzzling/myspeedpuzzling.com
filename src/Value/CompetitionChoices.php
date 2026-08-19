<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The "Competition / event" picker payload for TomSelect plus the set of ids it offers, so the
 * submitted value can be validated against exactly what was rendered without a second query.
 */
readonly final class CompetitionChoices
{
    /**
     * @param list<array{value: string, text: string, keywords: string, optgroup?: string}> $options
     *        Options in display order; editions carry `optgroup` = series id, standalone events are ungrouped.
     * @param list<array{value: string, label: string, logo?: string}> $optgroups
     *        One per series that has at least one selectable edition; `value` = series id.
     * @param array<string, true> $ids Every option value, for O(1) membership checks.
     */
    public function __construct(
        public array $options,
        public array $optgroups,
        private array $ids,
    ) {
    }

    public function contains(string $competitionId): bool
    {
        return isset($this->ids[$competitionId]);
    }
}
