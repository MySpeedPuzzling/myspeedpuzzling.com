<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class AdminCompetitionDetail
{
    /**
     * @param list<AdminCompetitionRound> $rounds
     * @param list<AdminPuzzle> $taggedPuzzles
     * @param list<AdminCompetitionMaintainer> $maintainers
     */
    public function __construct(
        public AdminCompetition $competition,
        public array $rounds,
        public array $taggedPuzzles,
        public array $maintainers,
    ) {
    }

    /**
     * @return list<string>
     */
    public function maintainerIds(): array
    {
        return array_map(static fn (AdminCompetitionMaintainer $maintainer): string => $maintainer->playerId, $this->maintainers);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->competition->toArray(),
            'maintainers' => array_map(static fn (AdminCompetitionMaintainer $maintainer): array => $maintainer->toArray(), $this->maintainers),
            'rounds' => array_map(static fn (AdminCompetitionRound $round): array => $round->toArray(), $this->rounds),
            // The competition's own puzzles ("Competition puzzles" on the event page) - the puzzles of its tag
            'puzzles' => array_map(static fn (AdminPuzzle $puzzle): array => $puzzle->toArray(), $this->taggedPuzzles),
        ];
    }
}
