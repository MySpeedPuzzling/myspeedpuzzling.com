<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\EventUrlPath;

/**
 * Where an old event URL leads now (docs/features/organizations/README.md, D6): the row of event_url_redirect for the
 * path, its target resolved to the target's CURRENT slugs in one statement - so a target moved again since the row was
 * written (chained moves) is found where it is now.
 *
 * Never leads to a draft: a target that is a draft (or sits in a draft series - an edition, a round) has no public
 * address, so the old path keeps answering 404 - for its team too, who reach the draft from "You organize". The draft
 * flags are read in the same statement (`is_draft` of the target and of the series above it). Approval is not checked:
 * a page waiting for approval is reachable at its URL like any other.
 */
readonly final class GetEventUrlRedirect
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return null|array{route: string, params: array<string, string>} null = no row, or its target has no address
     *     (a slug missing)
     */
    public function target(EventUrlPath $path): null|array
    {
        /**
         * @var false|array{
         *     organization_slug: null|string,
         *     series_target_slug: null|string,
         *     competition_id: null|string,
         *     competition_slug: null|string,
         *     competition_series_id: null|string,
         *     competition_series_slug: null|string,
         *     round_id: null|string,
         *     round_slug: null|string,
         *     round_competition_slug: null|string,
         *     round_competition_series_id: null|string,
         *     round_series_slug: null|string,
         *     target_is_draft: bool,
         * } $row
         */
        $row = $this->database->fetchAssociative(<<<SQL
SELECT
    o.slug AS organization_slug,
    s.slug AS series_target_slug,
    c.id AS competition_id,
    c.slug AS competition_slug,
    c.series_id AS competition_series_id,
    c_s.slug AS competition_series_slug,
    cr.id AS round_id,
    cr.slug AS round_slug,
    r_c.slug AS round_competition_slug,
    r_c.series_id AS round_competition_series_id,
    r_s.slug AS round_series_slug,
    (
        COALESCE(o.is_draft, false)
        OR COALESCE(s.is_draft, false)
        OR COALESCE(c.is_draft, false)
        OR COALESCE(c_s.is_draft, false)
        OR COALESCE(r_c.is_draft, false)
        OR COALESCE(r_s.is_draft, false)
    ) AS target_is_draft
FROM event_url_redirect r
LEFT JOIN organization o ON o.id = r.organization_id
LEFT JOIN competition_series s ON s.id = r.series_id
LEFT JOIN competition c ON c.id = r.competition_id
LEFT JOIN competition_series c_s ON c_s.id = c.series_id
LEFT JOIN competition_round cr ON cr.id = r.round_id
LEFT JOIN competition r_c ON r_c.id = cr.competition_id
LEFT JOIN competition_series r_s ON r_s.id = r_c.series_id
WHERE r.series_slug = :seriesSlug
    AND r.competition_slug = :competitionSlug
    AND r.round_slug = :roundSlug
SQL, [
            'seriesSlug' => $path->seriesSlug,
            'competitionSlug' => $path->competitionSlug,
            'roundSlug' => $path->roundSlug,
        ]);

        if ($row === false || $row['target_is_draft'] === true) {
            return null;
        }

        if ($row['organization_slug'] !== null) {
            return ['route' => 'organization_detail', 'params' => ['slug' => $row['organization_slug']]];
        }

        if ($row['series_target_slug'] !== null) {
            return ['route' => 'competition_series_detail', 'params' => ['slug' => $row['series_target_slug']]];
        }

        if ($row['competition_id'] !== null) {
            return self::competitionAddress($row['competition_slug'], $row['competition_series_id'], $row['competition_series_slug'], null);
        }

        if ($row['round_id'] !== null && $row['round_slug'] !== null) {
            return self::competitionAddress($row['round_competition_slug'], $row['round_competition_series_id'], $row['round_series_slug'], $row['round_slug']);
        }

        return null;
    }

    /**
     * @return null|array{route: string, params: array<string, string>}
     */
    private static function competitionAddress(
        null|string $competitionSlug,
        null|string $seriesId,
        null|string $seriesSlug,
        null|string $roundSlug,
    ): null|array {
        // An edition of a series without a slug has no address of its own
        if ($competitionSlug === null || ($seriesId !== null && $seriesSlug === null)) {
            return null;
        }

        if ($seriesSlug !== null) {
            return $roundSlug === null
                ? ['route' => 'edition_detail', 'params' => ['seriesSlug' => $seriesSlug, 'editionSlug' => $competitionSlug]]
                : ['route' => 'edition_round_results', 'params' => ['seriesSlug' => $seriesSlug, 'editionSlug' => $competitionSlug, 'roundSlug' => $roundSlug]];
        }

        return $roundSlug === null
            ? ['route' => 'event_detail', 'params' => ['slug' => $competitionSlug]]
            : ['route' => 'event_round_results', 'params' => ['slug' => $competitionSlug, 'roundSlug' => $roundSlug]];
    }
}
