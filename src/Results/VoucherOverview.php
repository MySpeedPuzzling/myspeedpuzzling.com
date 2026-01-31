<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class VoucherOverview
{
    public function __construct(
        public string $id,
        public string $code,
        public int $monthsValue,
        public DateTimeImmutable $validUntil,
        public DateTimeImmutable $createdAt,
        public null|DateTimeImmutable $usedAt,
        public null|string $usedById,
        public null|string $usedByName,
        public null|string $internalNote,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $id = $row['id'];
        assert(is_string($id));
        $code = $row['code'];
        assert(is_string($code));
        $monthsValue = $row['months_value'];
        assert(is_int($monthsValue));
        $validUntil = $row['valid_until'];
        assert(is_string($validUntil));
        $createdAt = $row['created_at'];
        assert(is_string($createdAt));
        $usedAt = $row['used_at'];
        assert(is_string($usedAt) || $usedAt === null);

        return new self(
            id: $id,
            code: $code,
            monthsValue: $monthsValue,
            validUntil: new DateTimeImmutable($validUntil),
            createdAt: new DateTimeImmutable($createdAt),
            usedAt: $usedAt !== null ? new DateTimeImmutable($usedAt) : null,
            usedById: is_string($row['used_by_id']) ? $row['used_by_id'] : null,
            usedByName: is_string($row['used_by_name']) ? $row['used_by_name'] : null,
            internalNote: is_string($row['internal_note']) ? $row['internal_note'] : null,
        );
    }

    public function getMaskedCode(): string
    {
        $length = strlen($this->code);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        $visibleStart = 4;
        $visibleEnd = 2;
        $maskedLength = $length - $visibleStart - $visibleEnd;

        return substr($this->code, 0, $visibleStart)
            . str_repeat('*', $maskedLength)
            . substr($this->code, -$visibleEnd);
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now > $this->validUntil;
    }

    public function isAvailable(DateTimeImmutable $now): bool
    {
        return !$this->isUsed() && !$this->isExpired($now);
    }
}
