<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Export;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\TransferType;

readonly final class ExportableLendBorrowTransfer
{
    public const array COLUMNS = ['transfer_id', 'lent_puzzle_id', 'transferred_at', 'transfer_type', 'from_name', 'to_name', 'owner_name', 'my_role', 'is_active'];

    public function __construct(
        public string $transferId,
        public null|string $lentPuzzleId,
        public DateTimeImmutable $transferredAt,
        public TransferType $transferType,
        public null|string $fromName,
        public null|string $toName,
        public null|string $ownerName,
        public string $myRole,
        public null|ExportablePuzzle $puzzle,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row, string $uploadedAssetsBaseUrl): self
    {
        /** @var array{transfer_id: string, lent_puzzle_id: null|string, transferred_at: string, transfer_type: string, from_name: null|string, to_name: null|string, owner_name: null|string, my_role: string} $row */
        return new self(
            transferId: $row['transfer_id'],
            lentPuzzleId: $row['lent_puzzle_id'],
            transferredAt: new DateTimeImmutable($row['transferred_at']),
            transferType: TransferType::from($row['transfer_type']),
            fromName: $row['from_name'],
            toName: $row['to_name'],
            ownerName: $row['owner_name'],
            myRole: $row['my_role'],
            puzzle: ExportablePuzzle::fromDatabaseRow($row, $uploadedAssetsBaseUrl),
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toColumns(): array
    {
        return [
            'transfer_id' => $this->transferId,
            'lent_puzzle_id' => $this->lentPuzzleId,
            'transferred_at' => $this->transferredAt->format('Y-m-d H:i:s'),
            'transfer_type' => $this->transferType->value,
            'from_name' => $this->fromName,
            'to_name' => $this->toName,
            'owner_name' => $this->ownerName,
            'my_role' => $this->myRole,
            // A finished loan keeps its history rows; the lent puzzle row (and so the link) is gone
            'is_active' => $this->lentPuzzleId !== null,
        ];
    }
}
