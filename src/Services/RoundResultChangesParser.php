<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\NewRoundEntry;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\RoundResultChange;
use SpeedPuzzling\Web\Value\RoundResultField;

/**
 * Reads the `changes` of an official results change set (RecordRoundResultsController) into RoundResultChange values:
 *
 *     {"clientChangeId": "<uuid>", "entry": "participant_round:<uuid>" | "team:<uuid>",
 *      "field": "result" | "table_number" | "qualified", "from": <value>, "to": <value>}
 *
 * or, for an entrant typed in at the venue, `newEntry` instead of `entry`:
 *
 *     {"clientEntryId": "<uuid>", "kind": "person", "name": "Jo Doe", "country": "cz"}
 *     {"clientEntryId": "<uuid>", "kind": "team", "name": "Puzzle Sharks", "members": ["Jo Doe", {"name": "Kim Example", "country": "de"}]}
 *
 * A change without a readable `clientChangeId` makes the whole request unreadable (InvalidArgumentException - there
 * is nothing to answer it by); any other problem refuses just that change (`invalid_change`).
 */
final readonly class RoundResultChangesParser
{
    public const int MAX_CHANGES = 500;

    /**
     * @return list<RoundResultChange>
     * @throws \InvalidArgumentException
     */
    public static function parse(mixed $changes): array
    {
        if (!is_array($changes) || !array_is_list($changes)) {
            throw new \InvalidArgumentException('"changes" must be a list.');
        }

        if (count($changes) > self::MAX_CHANGES) {
            throw new \InvalidArgumentException(sprintf('At most %d changes per request.', self::MAX_CHANGES));
        }

        $parsed = [];
        $seen = [];

        foreach ($changes as $change) {
            $clientChangeId = is_array($change) ? ($change['clientChangeId'] ?? null) : null;

            if (!is_string($clientChangeId) || !Uuid::isValid($clientChangeId)) {
                throw new \InvalidArgumentException('Every change needs a "clientChangeId" (UUID).');
            }

            if (isset($seen[strtolower($clientChangeId)])) {
                throw new \InvalidArgumentException('A "clientChangeId" is used twice in one request.');
            }

            $seen[strtolower($clientChangeId)] = true;
            $parsed[] = self::change($clientChangeId, $change);
        }

        return $parsed;
    }

    /**
     * @param array<mixed> $change
     */
    private static function change(string $clientChangeId, array $change): RoundResultChange
    {
        $field = is_string($change['field'] ?? null) ? RoundResultField::tryFrom($change['field']) : null;

        if ($field === null || !array_key_exists('from', $change) || !array_key_exists('to', $change)) {
            return RoundResultChange::unreadable($clientChangeId, 'invalid_change');
        }

        if (isset($change['newEntry'])) {
            $entry = self::newEntry($change['newEntry']);
        } else {
            $entry = RoundEntryRef::tryFromString($change['entry'] ?? null);
        }

        if ($entry === null) {
            return RoundResultChange::unreadable($clientChangeId, 'invalid_change');
        }

        try {
            $from = self::value($field, $change['from']);
            $to = self::value($field, $change['to']);
        } catch (\InvalidArgumentException) {
            return RoundResultChange::unreadable($clientChangeId, 'invalid_change');
        }

        return RoundResultChange::forEntry($clientChangeId, $entry, $field, $from, $to);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function value(RoundResultField $field, mixed $value): null|RoundEntryResult|int|bool
    {
        return match ($field) {
            RoundResultField::Result => RoundEntryResult::fromWire($value),
            RoundResultField::TableNumber => $value === null || is_int($value) ? $value : throw new \InvalidArgumentException('A table number is an integer or null.'),
            RoundResultField::Qualified => is_bool($value) ? $value : throw new \InvalidArgumentException('Qualified is true or false.'),
        };
    }

    private static function newEntry(mixed $value): null|NewRoundEntry
    {
        if (!is_array($value)) {
            return null;
        }

        $clientEntryId = $value['clientEntryId'] ?? null;
        $kind = $value['kind'] ?? null;

        if (!is_string($clientEntryId) || !Uuid::isValid($clientEntryId)) {
            return null;
        }

        if ($kind !== NewRoundEntry::KIND_PERSON && $kind !== NewRoundEntry::KIND_TEAM) {
            return null;
        }

        $name = $value['name'] ?? null;

        if ($name !== null && !is_string($name)) {
            return null;
        }

        $country = self::country($value['country'] ?? null);

        if ($country === false) {
            return null;
        }

        $members = [];
        $memberValues = $value['members'] ?? [];

        if (!is_array($memberValues) || !array_is_list($memberValues) || ($kind === NewRoundEntry::KIND_PERSON && $memberValues !== [])) {
            return null;
        }

        foreach ($memberValues as $member) {
            $memberName = is_array($member) ? ($member['name'] ?? null) : $member;
            $memberCountry = self::country(is_array($member) ? ($member['country'] ?? null) : null);
            $memberName = is_string($memberName) ? CompetitionTeam::cleanName($memberName) : null;

            if ($memberName === null || $memberCountry === false) {
                return null;
            }

            $members[] = ['name' => $memberName, 'country' => $memberCountry];
        }

        return new NewRoundEntry(
            clientEntryId: strtolower($clientEntryId),
            kind: $kind,
            name: CompetitionTeam::cleanName($name),
            country: $kind === NewRoundEntry::KIND_PERSON ? $country : null,
            members: $members,
        );
    }

    /**
     * @return null|string|false the CountryCode case name, null for none, false for an unknown country
     */
    private static function country(mixed $value): null|string|false
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            return false;
        }

        $country = CountryCode::fromCode($value);

        return $country !== null ? $country->name : false;
    }
}
