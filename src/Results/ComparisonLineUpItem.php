<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;

/**
 * One row of a player's comparison line-ups (docs/features/player-comparison.md) - what it is, never who: names and
 * whether the viewer may see the subject are the read side's business, decided on every read.
 *
 * @phpstan-type ComparisonLineUpItemRow array{
 *     id: string,
 *     subject_player_id: null|string,
 *     subject_team_id: null|string,
 *     team_size: null|int|string,
 *     added_at: string,
 *     is_self: bool,
 *     ...
 * }
 */
readonly final class ComparisonLineUpItem
{
    public function __construct(
        // The comparison_subject id - what removing or swapping it takes
        public string $rowId,
        public ComparisonSubjectRef $ref,
        public ComparisonKind $kind,
        public DateTimeImmutable $addedAt,
        // The owner in their own Solo line-up
        public bool $isSelf,
    ) {
    }

    /**
     * @param ComparisonLineUpItemRow $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        if ($row['subject_team_id'] !== null) {
            $ref = ComparisonSubjectRef::team($row['subject_team_id']);
            $kind = ComparisonKind::forTeamSize((int) $row['team_size']);
        } else {
            $ref = ComparisonSubjectRef::player((string) $row['subject_player_id']);
            $kind = ComparisonKind::Solo;
        }

        return new self(
            rowId: $row['id'],
            ref: $ref,
            kind: $kind,
            addedAt: new DateTimeImmutable($row['added_at']),
            isSelf: $row['is_self'],
        );
    }
}
