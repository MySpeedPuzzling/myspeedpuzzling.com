<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;

/**
 * One field of a puzzle as a decision found it and left it - or, for a proposal, as it was and as proposed.
 */
readonly final class PuzzleHistoryChange
{
    private const array FIELDS = [
        'name' => 'Name',
        'nameLanguage' => 'Name language',
        'alternativeNames' => 'Other names',
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
     * a field missing from either snapshot was not recorded and is left out. The other names are a list
     * (`alternativeNames`) since the puzzle names, a single `alternativeName` string in the snapshots before.
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

            if (self::isRecorded($field, $before) === false || self::isRecorded($field, $after) === false) {
                continue;
            }

            $beforeValue = self::value($field, $before);
            $afterValue = self::value($field, $after);

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

    /**
     * @param array<mixed> $snapshot
     */
    private static function isRecorded(string $field, array $snapshot): bool
    {
        if ($field === 'alternativeNames') {
            return array_key_exists('alternativeNames', $snapshot) || array_key_exists('alternativeName', $snapshot);
        }

        return array_key_exists($field, $snapshot);
    }

    /**
     * @param array<mixed> $snapshot
     */
    private static function value(string $field, array $snapshot): null|string
    {
        if ($field !== 'alternativeNames') {
            return self::text($snapshot[$field] ?? null);
        }

        if (array_key_exists('alternativeNames', $snapshot) === false) {
            return self::text($snapshot['alternativeName'] ?? null);
        }

        $names = is_array($snapshot['alternativeNames']) ? PuzzleNames::fromArray($snapshot['alternativeNames']) : new PuzzleNames();

        if ($names->isEmpty()) {
            return null;
        }

        // "Kruh barev: Mušle (cs), Seashells"
        return implode(', ', array_map(
            static fn (PuzzleName $name): string => $name->name . ($name->language !== null ? ' (' . $name->language . ')' : ''),
            $names->all(),
        ));
    }

    private static function text(mixed $value): null|string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
