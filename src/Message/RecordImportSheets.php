<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The file was opened: its sheets, before any row. Answers whether reading goes on.
 */
readonly final class RecordImportSheets
{
    /**
     * @param list<array{id: string, position: int, title: string, hidden: bool, hints: null|array<string, mixed>}> $sheets
     */
    public function __construct(
        public string $importId,
        public int $readAttempt,
        public array $sheets,
    ) {
    }
}
