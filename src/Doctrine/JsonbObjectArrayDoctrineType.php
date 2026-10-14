<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonbType;

/**
 * A JSONB column that holds a JSON object, read as an associative array (OnlineEntryEvent::$payload). Doctrine's json
 * types write an empty PHP array as `[]`, so an event without data was stored as a list among objects - this one
 * writes it as `{}`. Read: whatever the column holds decodes as Doctrine's jsonb does, so a `[]` stored before reads
 * as an empty array all the same. The column's SQL is plain JSONB - no schema change.
 */
final class JsonbObjectArrayDoctrineType extends JsonbType
{
    public const string NAME = 'jsonb_object_array';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): null|string
    {
        if ($value === []) {
            return '{}';
        }

        return parent::convertToDatabaseValue($value, $platform);
    }
}
