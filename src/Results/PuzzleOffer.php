<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class PuzzleOffer
{
    public function __construct(
        public string $itemId,
        public string $playerId,
        public null|string $playerName,
        public string $playerCode,
        public null|string $playerCountry,
        public null|string $comment,
        public null|string $price,
        public null|string $currency,
        public null|string $condition,
        public DateTimeImmutable $addedAt,
        public string $saleType,
    ) {
    }

    /**
     * @param array{
     *     item_id: string,
     *     player_id: string,
     *     player_name: null|string,
     *     player_code: string,
     *     player_country: null|string,
     *     comment: null|string,
     *     price: null|string,
     *     currency: null|string,
     *     condition: null|string,
     *     added_at: string,
     *     system_type: string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        // Parse sale type from comment
        $saleType = 'sell';
        if ($row['comment'] !== null) {
            if (stripos($row['comment'], 'Type: lend') !== false) {
                $saleType = 'lend';
            }
            // Remove the Type: prefix from comment for display
            $row['comment'] = preg_replace('/^Type: (sell|lend)\n?/i', '', $row['comment']);
        }

        return new self(
            itemId: $row['item_id'],
            playerId: $row['player_id'],
            playerName: $row['player_name'],
            playerCode: $row['player_code'],
            playerCountry: $row['player_country'],
            comment: $row['comment'] !== '' ? $row['comment'] : null,
            price: $row['price'],
            currency: $row['currency'],
            condition: $row['condition'],
            addedAt: new DateTimeImmutable($row['added_at']),
            saleType: $saleType,
        );
    }

    public function getPriceFormatted(): string
    {
        if ($this->price === null) {
            return '-';
        }
        $currency = $this->currency ?? 'USD';
        return $currency . ' ' . number_format((float) $this->price, 2);
    }
}
