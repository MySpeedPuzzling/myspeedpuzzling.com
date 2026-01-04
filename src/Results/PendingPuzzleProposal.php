<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class PendingPuzzleProposal
{
    /**
     * @param array<MergePuzzleInfo> $mergePuzzles
     */
    public function __construct(
        public string $id,
        public string $type,
        public DateTimeImmutable $submittedAt,
        public null|string $reporterName,
        public null|string $reporterCode,
        public string $summary,
        public array $mergePuzzles = [],
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param array<MergePuzzleInfo> $mergePuzzles
     */
    public static function fromDatabaseRow(array $row, array $mergePuzzles = []): self
    {
        $id = $row['id'];
        assert(is_string($id));
        $type = $row['type'];
        assert(is_string($type));
        $submittedAt = $row['submitted_at'];
        assert(is_string($submittedAt));
        $summary = $row['summary'];
        assert(is_string($summary));

        return new self(
            id: $id,
            type: $type,
            submittedAt: new DateTimeImmutable($submittedAt),
            reporterName: is_string($row['reporter_name']) ? $row['reporter_name'] : null,
            reporterCode: is_string($row['reporter_code']) ? $row['reporter_code'] : null,
            summary: $summary,
            mergePuzzles: $mergePuzzles,
        );
    }
}
