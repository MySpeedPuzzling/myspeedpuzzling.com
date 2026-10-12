<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\JsonType;
use SpeedPuzzling\Web\Value\Lap;

final class LapsArrayDoctrineType extends JsonType
{
    public const string NAME = 'laps[]';

    // Milliseconds since 2026-10-12: whole seconds at both ends of a lap made a saved time up to a second longer
    private const string FORMAT = 'Y-m-d H:i:s.v';

    private const string LEGACY_FORMAT = 'Y-m-d H:i:s';

    /**
     * @return null|array<Lap>
     *
     * @throws InvalidType
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): null|array
    {
        if ($value === null) {
            return null;
        }

        $jsonData = parent::convertToPHPValue($value, $platform);
        assert(is_array($jsonData));

        $laps = [];

        foreach ($jsonData as $lapData) {
            /** @var array{start: string, end: null|string} $lapData */

            $laps[] = new Lap(
                self::parse($lapData['start']),
                $lapData['end'] === null ? null : self::parse($lapData['end']),
            );
        }

        return $laps;
    }

    /**
     * @param null|array<Lap> $value
     * @throws InvalidType
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): null|string
    {
        if ($value === null) {
            return null;
        }

        $data = [];

        foreach ($value as $lap) {
            $data[] = [
                'start' => $lap->start->format(self::FORMAT),
                'end' => $lap->end?->format(self::FORMAT),
            ];
        }

        return parent::convertToDatabaseValue($data, $platform);
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $value)
            ?: DateTimeImmutable::createFromFormat('!' . self::LEGACY_FORMAT, $value);
        assert($date instanceof DateTimeImmutable);

        return $date;
    }
}
