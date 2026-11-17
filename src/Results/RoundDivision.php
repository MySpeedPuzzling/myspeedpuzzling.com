<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One division a round uses (shared-puzzles-and-divisions.md §3, D2) - on the round (`divisions`, in the event's order)
 * and on each of its entries (`division`). `label()` = the short label, else the name.
 */
readonly final class RoundDivision implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $name,
        public null|string $shortName,
    ) {
    }

    public function label(): string
    {
        return $this->shortName ?? $this->name;
    }

    /**
     * The round's divisions as RoundDivisionsJson::sql() reads them - none for null (a round without divisions).
     *
     * @return list<self>
     */
    public static function listFromJson(null|string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        /** @var list<array{id: string, name: string, shortName: null|string}> $rows */
        $rows = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return array_map(static fn (array $row): self => new self($row['id'], $row['name'], $row['shortName']), $rows);
    }

    /**
     * @return array{id: string, name: string, shortName: null|string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'shortName' => $this->shortName,
        ];
    }
}
