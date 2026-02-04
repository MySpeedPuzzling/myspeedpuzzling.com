<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\VoucherType;

readonly final class VoucherOverview
{
    public function __construct(
        public string $id,
        public string $code,
        public null|int $monthsValue,
        public DateTimeImmutable $validUntil,
        public DateTimeImmutable $createdAt,
        public null|DateTimeImmutable $usedAt,
        public null|string $usedById,
        public null|string $usedByName,
        public null|string $internalNote,
        public VoucherType $voucherType,
        public null|int $percentageDiscount,
        public int $maxUses,
        public int $usageCount,
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
        $validUntil = $row['valid_until'];
        assert(is_string($validUntil));
        $createdAt = $row['created_at'];
        assert(is_string($createdAt));
        $usedAt = $row['used_at'];
        assert(is_string($usedAt) || $usedAt === null);

        $voucherTypeString = $row['voucher_type'] ?? 'free_months';
        assert(is_string($voucherTypeString));
        $voucherType = VoucherType::tryFrom($voucherTypeString) ?? VoucherType::FreeMonths;

        $monthsValue = $row['months_value'];
        assert(is_int($monthsValue) || $monthsValue === null);

        $percentageDiscount = $row['percentage_discount'] ?? null;
        assert(is_int($percentageDiscount) || $percentageDiscount === null);

        $maxUses = $row['max_uses'] ?? 1;
        assert(is_int($maxUses));

        $usageCount = $row['usage_count'] ?? 0;
        assert(is_int($usageCount) || is_string($usageCount));
        $usageCount = (int) $usageCount;

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
            voucherType: $voucherType,
            percentageDiscount: $percentageDiscount,
            maxUses: $maxUses,
            usageCount: $usageCount,
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
        // For free_months vouchers, check usedAt
        // For percentage_discount vouchers, check if usage count reached max
        if ($this->voucherType === VoucherType::FreeMonths) {
            return $this->usedAt !== null;
        }

        return $this->usageCount >= $this->maxUses;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now > $this->validUntil;
    }

    public function isAvailable(DateTimeImmutable $now): bool
    {
        return !$this->isUsed() && !$this->isExpired($now);
    }

    public function getValue(): string
    {
        if ($this->voucherType === VoucherType::PercentageDiscount) {
            return $this->percentageDiscount . '%';
        }

        return $this->monthsValue . ' month' . ($this->monthsValue !== 1 ? 's' : '');
    }

    public function getUsageDisplay(): string
    {
        if ($this->voucherType === VoucherType::FreeMonths) {
            return $this->usedAt !== null ? 'Used' : 'Available';
        }

        return $this->usageCount . '/' . $this->maxUses;
    }
}
