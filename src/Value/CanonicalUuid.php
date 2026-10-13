<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A UUID exactly as a phone writes it - 8-4-4-4-12 hex digits, nothing around them. Ids that come from a client (the
 * participant API's events, media, rounds) are checked with this, not Uuid::isValid(): that one also takes `{…}`,
 * `urn:uuid:…` and other spellings of the same UUID, which would then live on as different strings (acks, keys).
 */
final readonly class CanonicalUuid
{
    public const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    /**
     * @phpstan-assert-if-true non-empty-string $value
     */
    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }
}
