<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One field of a puzzle as a decision found it and left it - or, for a proposal, as it was and as proposed.
 */
readonly final class PuzzleHistoryChange
{
    private const array FIELDS = [
        'name' => 'Name',
        'alternativeName' => 'Alternative name',
        'manufacturer' => 'Brand',
        'piecesCount' => 'Pieces',
        'ean' => 'EAN',
        'identificationNumber' => 'Brand code',
        'image' => 'Image',
    ];

    public function __construct(
        public string $label,
        public null|string $before,
        public null|string $after,
        // Before and after are image paths
        public bool $image = false,
    ) {
    }

    /**
     * The fields that differ between two snapshots of PuzzleRecordUpdater::snapshot() - or of an older shape:
     * a field missing from either snapshot was not recorded and is left out.
     *
     * @param array<mixed> $before
     * @param array<mixed> $after
     *
     * @return list<self>
     */
    public static function between(array $before, array $after): array
    {
        $changes = [];

        foreach (self::FIELDS as $field => $label) {
            if ($field === 'manufacturer') {
                if (array_key_exists('manufacturerName', $before) === false || array_key_exists('manufacturerName', $after) === false) {
                    continue;
                }

                $differs = array_key_exists('manufacturerId', $before) && array_key_exists('manufacturerId', $after)
                    ? $before['manufacturerId'] !== $after['manufacturerId']
                    : $before['manufacturerName'] !== $after['manufacturerName'];

                if ($differs) {
                    $changes[] = new self($label, self::text($before['manufacturerName']), self::text($after['manufacturerName']));
                }

                continue;
            }

            if (array_key_exists($field, $before) === false || array_key_exists($field, $after) === false) {
                continue;
            }

            $beforeValue = self::text($before[$field]);
            $afterValue = self::text($after[$field]);

            if ($beforeValue !== $afterValue) {
                $changes[] = new self($label, $beforeValue, $afterValue, image: $field === 'image');
            }
        }

        return $changes;
    }

    public static function label(string $field): string
    {
        return self::FIELDS[$field] ?? $field;
    }

    private static function text(mixed $value): null|string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
