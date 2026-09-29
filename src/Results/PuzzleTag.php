<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PuzzleTag
{
    public function __construct(
        public string $tagId,
        public string $name,
        /** The publicly visible competition (or series) the tag belongs to - only GetTags::forPuzzle() loads it */
        public null|CompetitionReference $competition = null,
    ) {
    }

    /**
     * @param array{
     *     tag_id: string,
     *     name: string,
     *     competition_name?: null|string,
     *     competition_slug?: null|string,
     *     competition_series_name?: null|string,
     *     competition_series_slug?: null|string,
     *     competition_is_series?: null|bool,
     *     ...
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $competitionName = $row['competition_name'] ?? null;

        return new self(
            tagId: $row['tag_id'],
            name: $row['name'],
            competition: $competitionName === null ? null : new CompetitionReference(
                name: $competitionName,
                slug: $row['competition_slug'] ?? null,
                seriesName: $row['competition_series_name'] ?? null,
                seriesSlug: $row['competition_series_slug'] ?? null,
                isSeries: $row['competition_is_series'] ?? false,
            ),
        );
    }
}
