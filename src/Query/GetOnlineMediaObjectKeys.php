<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineReferenceImages;
use SpeedPuzzling\Web\Value\CanonicalUuid;

/**
 * Which object keys under `online/` still have their row - the orphan sweep deletes the rest: a deleted competition,
 * round or participant takes the rows with it (cascade), never the objects.
 *
 * - Evidence: a key ends with its media's id (`online/{competition}/{round}/{participant}/{media}.jpg`), so the rows are
 *   found by their primary key; a key without an id in that place has no row.
 * - A round's reference image (`online/{competition}/{round}/reference/{id}.jpg`, OnlineReferenceImages) is not evidence:
 *   its row is the online round holding the key (`online_round.reference_image`) - kept as long as one does.
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
        $references = [];

        foreach ($keys as $key) {
            if (OnlineReferenceImages::isObjectKey($key)) {
                $references[] = $key;

                continue;
            }

            $id = self::mediaId($key);

            if ($id !== null) {
                $ids[$id] = true;
            }
        }

        $stored = [];

        if ($ids !== []) {
            /** @var list<string> $evidence */
            $evidence = $this->database->fetchFirstColumn(
                'SELECT object_key FROM online_entry_media WHERE id IN (:ids)',
                ['ids' => array_keys($ids)],
                ['ids' => ArrayParameterType::STRING],
            );
            $stored = $evidence;
        }

        if ($references !== []) {
            /** @var list<string> $held */
            $held = $this->database->fetchFirstColumn(
                'SELECT reference_image FROM online_round WHERE reference_image IN (:keys)',
                ['keys' => $references],
                ['keys' => ArrayParameterType::STRING],
            );
            $stored = [...$stored, ...$held];
        }

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
