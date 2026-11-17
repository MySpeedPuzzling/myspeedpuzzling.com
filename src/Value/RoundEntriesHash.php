<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The validator of a round state's `entries` (judge-speed J2, decision D-6): a hash of the entries exactly as they go on
 * the wire - after RefereeEntriesView, so the referee's and the organiser's views never share one - computed on every
 * answer. No write path keeps it, so none can forget to: whatever a judge sees changes the bytes, the bytes change it.
 */
final class RoundEntriesHash
{
    public const string NONE = 'none';

    public static function of(mixed $entries): string
    {
        return hash('xxh128', (string) json_encode($entries, JsonResponse::DEFAULT_ENCODING_OPTIONS));
    }

    /**
     * The client's `entriesKnown` parameter: a hash it holds, `none`, or anything else = the parameter absent (null).
     */
    public static function known(string $value): null|string
    {
        if ($value === self::NONE || preg_match('/^[0-9a-f]{32}$/', $value) === 1) {
            return $value;
        }

        return null;
    }
}
