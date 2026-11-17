<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A person on a printed line-up or round entry tag (GetCompetitionLineupTags, GetRoundEntryTags): the participant as the
 * organiser recorded them, the linked player's code only when their profile is public (the name tags' rule - a tag is
 * seen in public, whoever prints it).
 */
readonly final class CompetitionTagMember
{
    public function __construct(
        public string $participantId,
        public string $name,
        public null|string $country,
        public null|string $playerCode,
    ) {
    }

    /**
     * @param array{id: string, name: string, country: null|string, code: null|string} $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row['id'], $row['name'], $row['country'], $row['code']);
    }
}
