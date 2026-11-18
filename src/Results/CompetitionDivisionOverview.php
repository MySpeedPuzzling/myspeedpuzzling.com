<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One division of an event as the divisions page and the internal API list it (GetCompetitionDivisions): with the
 * rounds that use it and how many round entries carry it (entries of removed participants left out).
 */
readonly final class CompetitionDivisionOverview implements \JsonSerializable
{
    /**
     * @param list<string> $roundIds
     */
    public function __construct(
        public string $id,
        public string $name,
        public null|string $shortName,
        public int $position,
        public array $roundIds,
        public int $entriesCount,
    ) {
    }

    /**
     * @return array{id: string, name: string, shortName: null|string, position: int, roundIds: list<string>, entriesCount: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'shortName' => $this->shortName,
            'position' => $this->position,
            'roundIds' => $this->roundIds,
            'entriesCount' => $this->entriesCount,
        ];
    }
}
