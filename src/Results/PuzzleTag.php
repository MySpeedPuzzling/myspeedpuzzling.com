<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PuzzleTag
{
    public function __construct(
        public string $tagId,
        public string $name,
    ) {
    }

    /**
     * @param array{
     *     tag_id: string,
     *     name: string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            tagId: $row['tag_id'],
            name: $row['name'],
        );
    }
}
