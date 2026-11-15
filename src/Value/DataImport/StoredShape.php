<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;

/**
 * Reads the parts of a stored (or submitted) mapping - JSON that came back as arrays - and refuses what is not the
 * shape it must be. One place, so that a mapping the player's browser sent is checked exactly like a stored one.
 *
 * @internal for the mapping's value objects
 */
final class StoredShape
{
    /**
     * @param array<mixed> $data
     */
    public static function string(array $data, string $key, int $maxLength = 500): string
    {
        $value = $data[$key] ?? null;

        if (is_string($value) === false || mb_strlen($value) > $maxLength) {
            throw new InvalidImportMapping(sprintf('"%s" must be a text of at most %d characters.', $key, $maxLength));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    public static function nullableString(array $data, string $key, int $maxLength = 500): null|string
    {
        return ($data[$key] ?? null) === null ? null : self::string($data, $key, $maxLength);
    }

    /**
     * @param array<mixed> $data
     */
    public static function int(array $data, string $key, int $min = 0, int $max = 2_000_000): int
    {
        $value = $data[$key] ?? null;

        if (is_int($value) === false || $value < $min || $value > $max) {
            throw new InvalidImportMapping(sprintf('"%s" must be a whole number from %d to %d.', $key, $min, $max));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    public static function nullableInt(array $data, string $key, int $min = 0, int $max = 2_000_000): null|int
    {
        return ($data[$key] ?? null) === null ? null : self::int($data, $key, $min, $max);
    }

    /**
     * @param array<mixed> $data
     */
    public static function bool(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? $default;

        if (is_bool($value) === false) {
            throw new InvalidImportMapping(sprintf('"%s" must be true or false.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<mixed>>
     */
    public static function items(array $data, string $key, int $most): array
    {
        $value = $data[$key] ?? [];

        if (is_array($value) === false || array_is_list($value) === false || count($value) > $most) {
            throw new InvalidImportMapping(sprintf('"%s" must be a list of at most %d items.', $key, $most));
        }

        $items = [];

        foreach ($value as $item) {
            if (is_array($item) === false) {
                throw new InvalidImportMapping(sprintf('Every item of "%s" must be an object.', $key));
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param array<mixed> $data
     * @param class-string<T> $enum
     *
     * @return T
     */
    public static function enum(array $data, string $key, string $enum): \BackedEnum
    {
        $value = $data[$key] ?? null;
        $case = is_string($value) || is_int($value) ? $enum::tryFrom($value) : null;

        if ($case === null) {
            throw new InvalidImportMapping(sprintf('"%s" is not one of the values it can be.', $key));
        }

        return $case;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param array<mixed> $data
     * @param class-string<T> $enum
     *
     * @return null|T
     */
    public static function nullableEnum(array $data, string $key, string $enum): null|\BackedEnum
    {
        return ($data[$key] ?? null) === null ? null : self::enum($data, $key, $enum);
    }
}
