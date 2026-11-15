<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Results\ImportPersonCard;
use SpeedPuzzling\Web\Results\ImportPersonSuggestion;
use SpeedPuzzling\Web\Value\DataImport\ImportPersonChoice;
use SpeedPuzzling\Web\Value\DataImport\ImportPersonDecidedHow;
use SpeedPuzzling\Web\Value\DataImport\ImportPersonText;
use SpeedPuzzling\Web\Value\DataImportStatus;

/**
 * "People in your file" (docs/features/data-import/people.md): the people an import's file names, who each is
 * decided to be, and - for names - the players that could be that person.
 *
 * **Suggestions come only from the importing player's own people** - registered members of their pairs and teams,
 * and their favourites - never from a search by name over everybody (the same name is many people, and a private
 * player would be found by name). Shared by the page and by DecideImportPersonHandler, so everything is written
 * out for **the player id it is given**, no security token: a block between the two in either direction leaves a
 * player out, and a private player is offered only when the importing player is on their allow list - the rules
 * HiddenPlayers and PrivateProfileAccess apply, for that id.
 */
readonly final class GetImportPeople
{
    public const int SUGGESTIONS_PER_PERSON = 5;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<ImportPersonCard> the most named first
     *
     * @throws DataImportNotFound not this player's import
     */
    public function ofImport(string $importId, string $playerId): array
    {
        if (Uuid::isValid($importId) === false || Uuid::isValid($playerId) === false) {
            throw new DataImportNotFound();
        }

        $owned = $this->database->fetchOne(
            'SELECT 1 FROM data_import WHERE id = :importId AND player_id = :playerId AND status <> :discarded',
            ['importId' => $importId, 'playerId' => $playerId, 'discarded' => DataImportStatus::Discarded->value],
        );

        if ($owned === false) {
            throw new DataImportNotFound();
        }

        $noBlock = self::sqlNoBlockBetween('linked.id');
        $nameHidden = self::sqlNameHidden('linked');

        /** @var list<array{id: string, person_key: string, name: string, rows_count: int, written_as_player: bool, choice: string, decided_how: string, example_row_ids: string, player_id: null|string, player_name: null|string, player_code: null|string, is_guest_of_theirs: bool}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT
    person.id,
    person.person_key,
    person.name,
    person.rows_count,
    person.written_as_player,
    person.choice,
    person.decided_how,
    person.example_row_ids,
    linked.id AS player_id,
    CASE WHEN {$nameHidden} THEN NULL ELSE linked.name END AS player_name,
    linked.code AS player_code,
    EXISTS (
        SELECT 1
        FROM puzzling_team_member guest
        INNER JOIN puzzling_team_member me ON me.team_id = guest.team_id AND me.player_id = :playerId
        WHERE guest.member_key = person.person_key AND guest.player_id IS NULL
    ) AS is_guest_of_theirs
FROM data_import_person person
-- A player who may not be added any more (a block since) is a guest for the page as for the engine
LEFT JOIN player linked ON linked.id = person.player_id AND person.choice = 'player' AND {$noBlock}
WHERE person.import_id = :importId
ORDER BY person.rows_count DESC, person.person_key
SQL, ['importId' => $importId, 'playerId' => $playerId])->fetchAllAssociative();

        $suggestable = $rows === [] ? [] : $this->suggestable($playerId);
        $cards = [];

        foreach ($rows as $row) {
            $choice = ImportPersonChoice::from($row['choice']);

            if ($choice === ImportPersonChoice::Player && $row['player_id'] === null) {
                $choice = ImportPersonChoice::Guest;
            }

            $exampleRowIds = json_decode($row['example_row_ids'], true);

            $cards[] = new ImportPersonCard(
                personId: $row['id'],
                personKey: $row['person_key'],
                name: $row['name'],
                rowsCount: $row['rows_count'],
                writtenAsPlayer: $row['written_as_player'],
                choice: $choice,
                decidedHow: ImportPersonDecidedHow::from($row['decided_how']),
                playerId: $row['player_id'],
                playerName: $row['player_name'],
                playerCode: $row['player_code'],
                exampleRowIds: is_array($exampleRowIds) ? array_values(array_filter($exampleRowIds, is_string(...))) : [],
                suggestions: $row['written_as_player'] ? [] : self::suggestionsFor($row['person_key'], $suggestable),
                isGuestOfTheirs: $row['is_guest_of_theirs'],
            );
        }

        return $cards;
    }

    /**
     * The card of the review before "Add": how many people the file names, how many are players, how many names
     * nobody said anything about (guests by default). One statement; an import of another player counts nothing.
     *
     * @return array{people: int, players: int, unanswered: int}
     */
    public function counts(string $importId, string $playerId): array
    {
        if (Uuid::isValid($importId) === false || Uuid::isValid($playerId) === false) {
            return ['people' => 0, 'players' => 0, 'unanswered' => 0];
        }

        /** @var false|array{people: int, players: int, unanswered: int} $row */
        $row = $this->database->executeQuery(<<<SQL
SELECT
    COUNT(*) AS people,
    COUNT(*) FILTER (WHERE person.choice = 'player' AND person.player_id IS NOT NULL) AS players,
    COUNT(*) FILTER (WHERE person.decided_how = 'default') AS unanswered
FROM data_import_person person
INNER JOIN data_import ON data_import.id = person.import_id
WHERE person.import_id = :importId
    AND data_import.player_id = :playerId
SQL, ['importId' => $importId, 'playerId' => $playerId])->fetchAssociative();

        return $row === false ? ['people' => 0, 'players' => 0, 'unanswered' => 0] : $row;
    }

    /**
     * The players the importing player may be offered - and may pick - for a name: their own people. One statement.
     *
     * @return array<string, array{id: string, name: null|string, code: string, country: null|string, name_key: null|string, together: bool}> by player id
     */
    public function suggestable(string $playerId): array
    {
        $noBlock = self::sqlNoBlockBetween('candidate.id');
        $nameHidden = self::sqlNameHidden('candidate');

        /** @var list<array{id: string, name: null|string, code: string, country: null|string, together: bool}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
WITH connected AS (
    -- Registered members of the importing player's pairs and teams
    SELECT other.player_id AS id, TRUE AS together
    FROM puzzling_team_member mine
    INNER JOIN puzzling_team_member other ON other.team_id = mine.team_id
    WHERE mine.player_id = :playerId
        AND other.player_id IS NOT NULL
        AND other.player_id <> :playerId
    UNION ALL
    -- Their favourites
    SELECT favourite.player_id::uuid AS id, FALSE AS together
    FROM player me
    CROSS JOIN LATERAL json_array_elements_text(me.favorite_players::json) AS favourite(player_id)
    WHERE me.id = :playerId
)
SELECT
    candidate.id,
    candidate.name,
    candidate.code,
    candidate.country,
    bool_or(connected.together) AS together
FROM connected
INNER JOIN player candidate ON candidate.id = connected.id
WHERE candidate.id <> :playerId
    AND {$noBlock}
    -- A private player only for somebody on their allow list
    AND NOT ({$nameHidden})
GROUP BY candidate.id
ORDER BY candidate.id
LIMIT 2000
SQL, ['playerId' => $playerId])->fetchAllAssociative();

        $byId = [];

        foreach ($rows as $row) {
            $byId[$row['id']] = [
                ...$row,
                'name_key' => $row['name'] === null || trim($row['name']) === '' ? null : ImportPersonText::nameKey($row['name']),
            ];
        }

        return $byId;
    }

    /**
     * Exact = the folded names are equal; maybe = only the first names are. Exact ones first, then the people the
     * importing player has results with.
     *
     * @param array<string, array{id: string, name: null|string, code: string, country: null|string, name_key: null|string, together: bool}> $suggestable
     *
     * @return list<ImportPersonSuggestion>
     */
    public static function suggestionsFor(string $personKey, array $suggestable): array
    {
        $firstName = self::firstName($personKey);
        $found = [];

        foreach ($suggestable as $candidate) {
            if ($candidate['name_key'] === null) {
                continue;
            }

            $exact = $candidate['name_key'] === $personKey;

            if ($exact === false && ($firstName === null || self::firstName($candidate['name_key']) !== $firstName)) {
                continue;
            }

            $found[] = new ImportPersonSuggestion($candidate['id'], $candidate['name'], $candidate['code'], $candidate['country'], $exact, $candidate['together']);
        }

        usort($found, static fn (ImportPersonSuggestion $a, ImportPersonSuggestion $b): int => [$b->exact, $b->solvedTogether, $a->playerId] <=> [$a->exact, $a->solvedTogether, $b->playerId]);

        return array_slice($found, 0, self::SUGGESTIONS_PER_PERSON);
    }

    /**
     * The first word of a folded name (`g:anna novak` → `anna`) - at least two letters, or it says nothing.
     */
    private static function firstName(string $nameKey): null|string
    {
        $name = str_starts_with($nameKey, 'g:') ? substr($nameKey, 2) : $nameKey;
        $first = explode(' ', trim($name))[0];

        return mb_strlen($first) >= 2 ? $first : null;
    }

    /**
     * No block between the importing player (`:playerId`) and the player in `$column`, in either direction.
     */
    private static function sqlNoBlockBetween(string $column): string
    {
        return <<<SQL
NOT EXISTS (
        SELECT 1 FROM user_block
        WHERE (user_block.blocker_id = {$column} AND user_block.blocked_id = :playerId)
            OR (user_block.blocker_id = :playerId AND user_block.blocked_id = {$column})
    )
SQL;
    }

    /**
     * The player behind `$alias` is private and the importing player (`:playerId`) is not on their allow list.
     */
    private static function sqlNameHidden(string $alias): string
    {
        return <<<SQL
{$alias}.is_private AND NOT EXISTS (
        SELECT 1 FROM private_profile_viewer
        WHERE private_profile_viewer.owner_id = {$alias}.id AND private_profile_viewer.viewer_id = :playerId
    )
SQL;
    }
}
