<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Export;

use DateTimeImmutable;

/**
 * A loan seen from one side: the counterparty is the current holder for puzzles lent out and the owner for borrowed
 * ones - a registered player's name (else `#CODE`), else the name typed in for somebody without an account.
 */
readonly final class ExportableLentPuzzle
{
    public function __construct(
        public string $lentPuzzleId,
        public DateTimeImmutable $lentAt,
        public null|string $notes,
        public null|string $counterpartyName,
        public null|string $counterpartyCode,
        public bool $counterpartyRegistered,
        public null|ExportablePuzzle $puzzle,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row, string $uploadedAssetsBaseUrl): self
    {
        /** @var array{lent_puzzle_id: string, lent_at: string, notes: null|string, counterparty_name: null|string, counterparty_code: null|string, counterparty_registered: bool} $row */
        return new self(
            lentPuzzleId: $row['lent_puzzle_id'],
            lentAt: new DateTimeImmutable($row['lent_at']),
            notes: $row['notes'],
            counterpartyName: $row['counterparty_name'],
            counterpartyCode: $row['counterparty_code'],
            counterpartyRegistered: $row['counterparty_registered'],
            puzzle: ExportablePuzzle::fromDatabaseRow($row, $uploadedAssetsBaseUrl),
        );
    }

    /**
     * @return list<string>
     */
    public static function columns(string $counterparty): array
    {
        return ['lent_puzzle_id', 'lent_at', 'notes', $counterparty . '_name', $counterparty . '_code', $counterparty . '_is_registered'];
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toColumns(string $counterparty): array
    {
        return [
            'lent_puzzle_id' => $this->lentPuzzleId,
            'lent_at' => $this->lentAt->format('Y-m-d H:i:s'),
            'notes' => $this->notes,
            $counterparty . '_name' => $this->counterpartyName,
            $counterparty . '_code' => $this->counterpartyCode,
            $counterparty . '_is_registered' => $this->counterpartyRegistered,
        ];
    }
}
