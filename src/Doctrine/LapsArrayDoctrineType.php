<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\JsonType;
use SpeedPuzzling\Web\Value\Lap;

final class LapsArrayDoctrineType extends JsonType
{
    public const NAME = 'laps[]';

    public function getName(): string
    {
        return self::NAME;
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }

    public function canRequireSQLConversion(): bool
    {
        return true;
    }

    /**
     * @return null|array<Lap>
     *
     * @throws ConversionException
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        if ($value === null) {
            return null;
        }

        $jsonData = parent::convertToPHPValue($value, $platform);
        assert(is_array($jsonData));

        $laps = [];

        foreach ($jsonData as $lapData) {
            $laps[] = new Lap(
                (new DateTimeImmutable())->setTimestamp($lapData['start']),
                $lapData['end'] === null ? null : (new DateTimeImmutable())->setTimestamp($lapData['end']),
            );
        }

        return $laps;
    }

    /**
     * @param null|array<Lap> $value
     * @throws ConversionException
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        $data = [];

        foreach ($value as $lap) {
            if (!is_a($lap, Lap::class)) {
                throw ConversionException::conversionFailedInvalidType($value, $this->getName(), [Lap::class]);
            }

            $data[] = [
                'start' => $lap->start->getTimestamp(),
                'end' => $lap->end?->getTimestamp(),
            ];
        }

        return parent::convertToDatabaseValue($data, $platform);
    }
}
