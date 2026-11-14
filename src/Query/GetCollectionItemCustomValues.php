<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\CustomValues;

/**
 * THE reader of the owner's own values on collection items (docs/features/custom-fields.md "Collection items") - the
 * only one, and only ever for the player who is signed in: every statement here is bound to the viewer's id, so
 * another player or a guest can not be handed somebody's values by a caller's mistake.
 *
 * Collection pages are often public and their lists are shared between viewers: `collection_item.custom_values` is
 * never selected by a collection page, list, card, the API, a share page, the marketplace or lending - not even for
 * the owner there. CustomValuesCoverageTest keeps it that way.
 */
readonly final class GetCollectionItemCustomValues
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * The viewer's own values on the given items of theirs, keyed by collection item id - items without any, and
     * anybody else's items, are left out.
     *
     * @param array<string> $collectionItemIds
     * @return array<string, CustomValues>
     */
    public function forViewer(array $collectionItemIds, string $viewerPlayerId): array
    {
        $collectionItemIds = array_values(array_filter($collectionItemIds, Uuid::isValid(...)));

        if ($collectionItemIds === [] || Uuid::isValid($viewerPlayerId) === false) {
            return [];
        }

        $query = <<<SQL
SELECT id, custom_values
FROM collection_item
WHERE player_id = :viewerPlayerId
    AND id IN (:collectionItemIds)
    AND custom_values IS NOT NULL
SQL;

        /** @var list<array{id: string, custom_values: string}> $rows */
        $rows = $this->database->executeQuery(
            $query,
            ['viewerPlayerId' => $viewerPlayerId, 'collectionItemIds' => $collectionItemIds],
            ['collectionItemIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return self::byItem($rows);
    }

    /**
     * Everything the viewer wrote on their own collection items, keyed by collection item id - their export: one
     * statement whatever the size of the library.
     *
     * @return array<string, CustomValues>
     */
    public function allOfViewer(string $viewerPlayerId): array
    {
        if (Uuid::isValid($viewerPlayerId) === false) {
            return [];
        }

        $query = <<<SQL
SELECT id, custom_values
FROM collection_item
WHERE player_id = :viewerPlayerId
    AND custom_values IS NOT NULL
SQL;

        /** @var list<array{id: string, custom_values: string}> $rows */
        $rows = $this->database->executeQuery($query, ['viewerPlayerId' => $viewerPlayerId])->fetchAllAssociative();

        return self::byItem($rows);
    }

    /**
     * @param list<array{id: string, custom_values: string}> $rows
     * @return array<string, CustomValues>
     */
    private static function byItem(array $rows): array
    {
        $values = [];

        foreach ($rows as $row) {
            $values[$row['id']] = CustomValues::fromStored($row['custom_values']);
        }

        return $values;
    }
}
