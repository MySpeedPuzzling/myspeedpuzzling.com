<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayDesignSanitizer;

/**
 * The uploaded pictures of every display of an event (or of a series' editions) - read before the event goes, so they
 * are deleted once it is gone (its displays cascade with it, D-displays-overlays.md §3.2).
 */
readonly final class GetCompetitionDisplayImagePaths
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<string>
     */
    public function ofCompetition(string $competitionId): array
    {
        return $this->paths('SELECT design FROM competition_display WHERE competition_id = :id', $competitionId);
    }

    /**
     * @return list<string>
     */
    public function ofSeries(string $seriesId): array
    {
        return $this->paths(
            'SELECT d.design FROM competition_display d INNER JOIN competition c ON c.id = d.competition_id WHERE c.series_id = :id',
            $seriesId,
        );
    }

    /**
     * @return list<string>
     */
    private function paths(string $query, string $id): array
    {
        /** @var list<string> $designs */
        $designs = $this->database->fetchFirstColumn($query, ['id' => $id]);
        $paths = [];

        foreach ($designs as $json) {
            $design = json_decode($json, true);

            if (is_array($design)) {
                array_push($paths, ...DisplayDesignSanitizer::imagePaths($design));
            }
        }

        return array_values(array_unique($paths));
    }
}
