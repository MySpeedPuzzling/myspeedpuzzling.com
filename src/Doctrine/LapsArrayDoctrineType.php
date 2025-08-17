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

            $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $lapData['start']);
            assert($start instanceof DateTimeImmutable);

            $end = $lapData['end'] === null ? null : DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $lapData['end']);
            assert($end === null || $end instanceof DateTimeImmutable);

            $laps[] = new Lap($start, $end);
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
            if (!is_a($lap, Lap::class)) {
                throw InvalidType::new($value, self::NAME, [Lap::class]);
            }

            $data[] = [
                'start' => $lap->start->format('Y-m-d H:i:s'),
                'end' => $lap->end?->format('Y-m-d H:i:s'),
            ];
        }

        return parent::convertToDatabaseValue($data, $platform);
    }
}
