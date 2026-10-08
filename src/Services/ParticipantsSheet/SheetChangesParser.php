<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantsSheet;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\UnreadableSheetChanges;
use SpeedPuzzling\Web\Value\SheetChange;
use SpeedPuzzling\Web\Value\SheetChangeField;
use SpeedPuzzling\Web\Value\SheetChangeGroup;
use SpeedPuzzling\Web\Value\SheetChangeOp;

/**
 * Reads a participants sheet change set (ApplyParticipantSheetChangesController) into values:
 *
 *     {"changesetId": "<uuid>", "dryRun": false, "groups": [{"id": "<page's group id>", "changes": [...]}]}
 *
 *     {"op": "newParticipant", "id": "<uuid>", "name": "Kim Example", "country": "us", "externalId": null}
 *     {"op": "field", "participant": "<uuid>", "field": "name" | "country" | "externalId" | "note", "from": …, "to": …}
 *     {"op": "player", "participant": "<uuid>", "from": null | "<player uuid>", "to": null | "<player uuid>"}
 *     {"op": "place", "participant": "<uuid>", "round": "<uuid>", "from": "out" | "in" | "team:<uuid>", "to": …}
 *     {"op": "newTeam", "id": "<uuid>", "round": "<uuid>", "name": null | "Corners"}
 *     {"op": "renameTeam", "team": "<uuid>", "from": null | "…", "to": null | "…"}
 *     {"op": "deleteTeam", "team": "<uuid>"}
 *     {"op": "remove", "participant": "<uuid>"}       {"op": "restore", "participant": "<uuid>"}
 *     {"op": "teamSize", "round": "<uuid>", "from": null | 4, "to": null | 4}
 *
 * The shape is checked here - a request that breaks it is a page bug, answered as a whole (UnreadableSheetChanges,
 * 400). The values are checked by the planner, change by change (a blank name, an unknown country, a team size of 50
 * are refusals the organiser reads next to the cell). Ids come out lower-cased.
 */
final readonly class SheetChangesParser
{
    public const int MAX_GROUPS = 1000;
    public const int MAX_CHANGES_PER_GROUP = 500;
    public const int MAX_CHANGES = 5000;
    public const int MAX_GROUP_ID_LENGTH = 64;

    /**
     * @return array{changesetId: null|string, dryRun: bool, groups: non-empty-list<SheetChangeGroup>}
     *         changesetId is null only on a dry run (nothing is stored then)
     *
     * @throws UnreadableSheetChanges
     */
    public static function parse(mixed $body): array
    {
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new UnreadableSheetChanges(UnreadableSheetChanges::NOT_A_LIST, 'The body must be a change set object.');
        }

        $dryRun = ($body['dryRun'] ?? false) === true;
        $changesetId = $body['changesetId'] ?? null;
        $changesetId = is_string($changesetId) && Uuid::isValid($changesetId) ? Uuid::fromString($changesetId)->toString() : null;

        // A change set that is applied is stored under its id - without one, a resend could apply it twice
        if ($dryRun === false && $changesetId === null) {
            throw new UnreadableSheetChanges(UnreadableSheetChanges::CHANGESET_ID_MISSING, 'A change set that is not a dry run needs a "changesetId" (UUID).');
        }

        $groups = $body['groups'] ?? null;

        if (!is_array($groups) || !array_is_list($groups) || $groups === []) {
            throw new UnreadableSheetChanges(UnreadableSheetChanges::NOT_A_LIST, '"groups" must be a non-empty list.');
        }

        if (count($groups) > self::MAX_GROUPS) {
            throw new UnreadableSheetChanges(UnreadableSheetChanges::TOO_MANY_GROUPS, sprintf('At most %d groups per change set.', self::MAX_GROUPS));
        }

        $parsed = [];
        $seen = [];
        $total = 0;

        foreach ($groups as $group) {
            if (!is_array($group)) {
                throw new UnreadableSheetChanges(UnreadableSheetChanges::NOT_A_LIST, 'Every group must be an object.');
            }

            $groupId = $group['id'] ?? null;

            if (!is_string($groupId) || $groupId === '' || mb_strlen($groupId) > self::MAX_GROUP_ID_LENGTH) {
                throw new UnreadableSheetChanges(UnreadableSheetChanges::INVALID_ID, sprintf('Every group needs an "id" of 1 to %d characters.', self::MAX_GROUP_ID_LENGTH));
            }

            if (isset($seen[$groupId])) {
                throw new UnreadableSheetChanges(UnreadableSheetChanges::DUPLICATE_GROUP_ID, 'A group id is used twice in one change set.');
            }

            $seen[$groupId] = true;
            $changes = $group['changes'] ?? null;

            if (!is_array($changes) || !array_is_list($changes) || $changes === []) {
                throw new UnreadableSheetChanges(UnreadableSheetChanges::NOT_A_LIST, '"changes" of a group must be a non-empty list.');
            }

            $total += count($changes);

            if (count($changes) > self::MAX_CHANGES_PER_GROUP || $total > self::MAX_CHANGES) {
                throw new UnreadableSheetChanges(UnreadableSheetChanges::TOO_MANY_CHANGES, sprintf('At most %d changes per group and %d per change set.', self::MAX_CHANGES_PER_GROUP, self::MAX_CHANGES));
            }

            $parsedChanges = [];
            foreach ($changes as $change) {
                $parsedChanges[] = self::change($change);
            }

            $parsed[] = new SheetChangeGroup($groupId, $parsedChanges);
        }

        return [
            'changesetId' => $changesetId,
            'dryRun' => $dryRun,
            'groups' => $parsed,
        ];
    }

    /**
     * @throws UnreadableSheetChanges
     */
    private static function change(mixed $change): SheetChange
    {
        if (!is_array($change)) {
            throw new UnreadableSheetChanges(UnreadableSheetChanges::NOT_A_LIST, 'Every change must be an object.');
        }

        $op = is_string($change['op'] ?? null) ? SheetChangeOp::tryFrom($change['op']) : null;

        if ($op === null) {
            throw new UnreadableSheetChanges(UnreadableSheetChanges::UNKNOWN_OP, 'Every change needs a known "op".');
        }

        return match ($op) {
            SheetChangeOp::NewParticipant => SheetChange::newParticipant(
                self::id($change, 'id'),
                self::string($change, 'name'),
                self::optionalString($change, 'country'),
                self::optionalString($change, 'externalId'),
            ),
            SheetChangeOp::Field => SheetChange::field(
                self::id($change, 'participant'),
                (is_string($change['field'] ?? null) ? SheetChangeField::tryFrom($change['field']) : null)
                    ?? throw new UnreadableSheetChanges(UnreadableSheetChanges::MISSING_FIELD, '"field" must be name, country, externalId or note.'),
                self::nullableString($change, 'from'),
                self::nullableString($change, 'to'),
            ),
            SheetChangeOp::Player => SheetChange::player(
                self::id($change, 'participant'),
                self::nullableId($change, 'from'),
                self::nullableId($change, 'to'),
            ),
            SheetChangeOp::Place => SheetChange::place(
                self::id($change, 'participant'),
                self::id($change, 'round'),
                self::place($change, 'from'),
                self::place($change, 'to'),
            ),
            SheetChangeOp::NewTeam => SheetChange::newTeam(
                self::id($change, 'id'),
                self::id($change, 'round'),
                self::optionalString($change, 'name'),
            ),
            SheetChangeOp::RenameTeam => SheetChange::renameTeam(
                self::id($change, 'team'),
                self::nullableString($change, 'from'),
                self::nullableString($change, 'to'),
            ),
            SheetChangeOp::DeleteTeam => SheetChange::deleteTeam(self::id($change, 'team')),
            SheetChangeOp::Remove => SheetChange::remove(self::id($change, 'participant')),
            SheetChangeOp::Restore => SheetChange::restore(self::id($change, 'participant')),
            SheetChangeOp::TeamSize => SheetChange::teamSize(
                self::id($change, 'round'),
                self::nullableInt($change, 'from'),
                self::nullableInt($change, 'to'),
            ),
        };
    }

    /**
     * @param array<mixed> $change
     * @throws UnreadableSheetChanges
     */
    private static function id(array $change, string $key): string
    {
        $value = $change[$key] ?? null;

        if ($value === null) {
            throw new UnreadableSheetChanges(UnreadableSheetChanges::MISSING_FIELD, sprintf('"%s" is missing.', $key));
        }

        if (!is_string($value) || !Uuid::isValid($value)) {
            throw new UnreadableSheetChanges(UnreadableSheetChanges::INVALID_ID, sprintf('"%s" must be a UUID.', $key));
        }

        // The canonical form - braces or a "urn:uuid:" prefix would never match a stored id
        return Uuid::fromString($value)->toString();
    }

    /**
     * A value the three-way check needs: the key must be there (null is a value).
     *
     * @param array<mixed> $change
     * @throws UnreadableSheetChanges
     */
    private static function nullableId(array $change, string $key): null|string
    {
        if (!array_key_exists($key, $change)) {
            throw new UnreadableSheetChanges(UnreadableSheetChanges::MISSING_FIELD, sprintf('"%s" is missing.', $key));
        }

        return $change[$key] === null ? null : self::id($change, $key);
    }

    /**
     * @param array<mixed> $change
     * @throws UnreadableSheetChanges
     */
    private static function string(array $change, string $key): string
    {
        $value = $change[$key] ?? null;

        if (!is_string($value)) {
            throw new UnreadableSheetChanges(UnreadableSheetChanges::MISSING_FIELD, sprintf('"%s" must be a string.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $change
     * @throws UnreadableSheetChanges
     */
    private static function nullableString(array $change, string $key): null|string
    {
        if (!array_key_exists($key, $change)) {
            throw new UnreadableSheetChanges(UnreadableSheetChanges::MISSING_FIELD, sprintf('"%s" is missing.', $key));
        }

        return self::optionalString($change, $key);
    }

    /**
     * Left out = null.
     *
     * @param array<mixed> $change
     * @throws UnreadableSheetChanges
     */
    private static function optionalString(array $change, string $key): null|string
    {
        $value = $change[$key] ?? null;

        if ($value !== null && !is_string($value)) {
            throw new UnreadableSheetChanges(UnreadableSheetChanges::MISSING_FIELD, sprintf('"%s" must be a string or null.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $change
     * @throws UnreadableSheetChanges
     */
    private static function nullableInt(array $change, string $key): null|int
    {
        if (!array_key_exists($key, $change)) {
            throw new UnreadableSheetChanges(UnreadableSheetChanges::MISSING_FIELD, sprintf('"%s" is missing.', $key));
        }

        $value = $change[$key];

        if ($value !== null && !is_int($value)) {
            throw new UnreadableSheetChanges(UnreadableSheetChanges::MISSING_FIELD, sprintf('"%s" must be a whole number or null.', $key));
        }

        return $value;
    }

    /**
     * `out`, `in` or `team:<uuid>`.
     *
     * @param array<mixed> $change
     * @throws UnreadableSheetChanges
     */
    private static function place(array $change, string $key): string
    {
        $value = $change[$key] ?? null;

        if ($value === SheetChange::OUT || $value === SheetChange::IN) {
            return $value;
        }

        if (!is_string($value) || !str_starts_with($value, SheetChange::TEAM_PREFIX)) {
            throw new UnreadableSheetChanges(UnreadableSheetChanges::MISSING_FIELD, sprintf('"%s" must be "out", "in" or "team:<id>".', $key));
        }

        $teamId = substr($value, strlen(SheetChange::TEAM_PREFIX));

        if (!Uuid::isValid($teamId)) {
            throw new UnreadableSheetChanges(UnreadableSheetChanges::INVALID_ID, sprintf('"%s" names a team without a UUID.', $key));
        }

        return SheetChange::teamPlace(Uuid::fromString($teamId)->toString());
    }
}
