<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\CustomFieldOverview;

/**
 * The signed-in player's own fields (docs/features/custom-fields.md) - for their own pages only: the caller passes
 * the id of the player who is signed in, never an id from a URL.
 */
readonly final class GetCustomFields
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Every field in the player's order, the archived ones last.
     *
     * @return list<CustomFieldOverview>
     */
    public function ofPlayer(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            return [];
        }

        $query = <<<SQL
SELECT
    custom_field.id,
    custom_field.name,
    custom_field.standard_key,
    custom_field.type,
    custom_field.choices,
    custom_field.for_results,
    custom_field.for_collection_items,
    custom_field.archived_at IS NOT NULL AS archived
FROM player_custom_field custom_field
WHERE custom_field.player_id = :playerId
ORDER BY custom_field.archived_at IS NOT NULL, custom_field.position, custom_field.created_at
SQL;

        /**
         * @var list<array{
         *     id: string,
         *     name: string,
         *     standard_key: null|string,
         *     type: string,
         *     choices: null|string,
         *     for_results: bool,
         *     for_collection_items: bool,
         *     archived: bool,
         * }> $rows
         */
        $rows = $this->database->executeQuery($query, ['playerId' => $playerId])->fetchAllAssociative();

        return array_map(CustomFieldOverview::fromDatabaseRow(...), $rows);
    }

    /**
     * The fields the player fills on a result: for results, not archived.
     *
     * @return list<CustomFieldOverview>
     */
    public function usableOnResults(string $playerId): array
    {
        return array_values(array_filter(
            $this->ofPlayer($playerId),
            static fn (CustomFieldOverview $field): bool => $field->isUsableOnResults(),
        ));
    }

    /**
     * The fields the player fills on a puzzle of their collection: for collection items, not archived.
     *
     * @return list<CustomFieldOverview>
     */
    public function usableOnCollectionItems(string $playerId): array
    {
        return array_values(array_filter(
            $this->ofPlayer($playerId),
            static fn (CustomFieldOverview $field): bool => $field->isUsableOnCollectionItems(),
        ));
    }
}
