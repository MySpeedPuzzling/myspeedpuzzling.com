<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\CanonicalUuid;

/**
 * Which object keys still have their evidence row (online_entry_media) - the orphan sweep deletes the rest: a deleted
 * competition, round or participant takes the rows with it (cascade), never the objects.
 *
 * A key ends with its media's id (`online/{competition}/{round}/{participant}/{media}.jpg`), so the rows are found by
 * their primary key; a key without an id in that place has no row.
 */
readonly final class GetOnlineMediaObjectKeys
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $keys
     * @return list<string> those of $keys some row holds
     */
    public function existing(array $keys): array
    {
        $ids = [];

        foreach ($keys as $key) {
            $id = self::mediaId($key);

            if ($id !== null) {
                $ids[$id] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        /** @var list<string> $stored */
        $stored = $this->database->fetchFirstColumn(
            'SELECT object_key FROM online_entry_media WHERE id IN (:ids)',
            ['ids' => array_keys($ids)],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_values(array_intersect($keys, $stored));
    }

    private static function mediaId(string $key): null|string
    {
        $file = basename($key);
        $dot = strrpos($file, '.');
        $id = $dot === false ? $file : substr($file, 0, $dot);

        return CanonicalUuid::isValid($id) ? strtolower($id) : null;
    }
}
