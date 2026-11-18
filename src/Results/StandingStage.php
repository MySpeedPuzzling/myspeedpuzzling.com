<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One stage of a `stages` standing (competition toolkit v2 S5, displays-standings.md §4.3) - a column of the bracket
 * (BracketLayout: groups 1 → semifinals 2 → final 3), its rounds in schedule order. Named by its rounds ("Group A /
 * Group B"), never by an invented "Semifinal". Classified = every round of it counts (public: published; organiser:
 * somebody is in it) - only then do its rounds place anybody.
 */
readonly final class StandingStage
{
    public const int NAMED_ROUNDS = 3;

    /**
     * @param array<string, string> $rounds round id => name, in schedule order
     */
    public function __construct(
        public int $number,
        public string $name,
        public array $rounds,
        public bool $classified,
    ) {
    }

    /**
     * "Group A / Group B / Group C +2" - at most NAMED_ROUNDS names.
     *
     * @param list<string> $roundNames
     */
    public static function nameOf(array $roundNames): string
    {
        $name = implode(' / ', array_slice($roundNames, 0, self::NAMED_ROUNDS));

        return count($roundNames) > self::NAMED_ROUNDS ? $name . ' +' . (count($roundNames) - self::NAMED_ROUNDS) : $name;
    }

    public function hasParallelRounds(): bool
    {
        return count($this->rounds) > 1;
    }

    /**
     * @return array{number: int, name: string, roundIds: list<string>, classified: bool}
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'name' => $this->name,
            'roundIds' => array_keys($this->rounds),
            'classified' => $this->classified,
        ];
    }
}
