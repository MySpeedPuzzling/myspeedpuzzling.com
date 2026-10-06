<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\DBAL\Connection;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * The URL slug of a competition (`/en/events/{slug}`): generated from the name, or chosen explicitly.
 *
 * The event page looks a competition up by its slug alone, so a standalone competition's slug is unique across every
 * competition. An edition's slug is only unique within its series (`competition (series_id, slug)`), because its
 * address is `/en/series/{seriesSlug}/{editionSlug}`.
 */
readonly final class CompetitionSlugGenerator
{
    /** Lower-case letters and digits in words joined by single hyphens - what the slugger produces */
    public const string PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public const int MAX_LENGTH = 255;

    public function __construct(
        private Connection $database,
        private SluggerInterface $slugger,
    ) {
    }

    /**
     * A slug from the name, with a random suffix when another competition holds it already.
     */
    public function generate(string $name, null|string $exceptCompetitionId = null): string
    {
        $slug = (string) $this->slugger->slug(strtolower($name));

        if ($this->isTaken($slug, null, $exceptCompetitionId)) {
            $slug .= '-' . substr(md5(uniqid()), 0, 6);
        }

        return $slug;
    }

    /**
     * Whether another competition holds the slug: any competition for a standalone one (`$seriesId` null), the
     * editions of the same series for an edition.
     */
    public function isTaken(string $slug, null|string $seriesId, null|string $exceptCompetitionId = null): bool
    {
        $taken = $this->database
            ->executeQuery(
                <<<SQL
SELECT EXISTS (
    SELECT 1
    FROM competition
    WHERE slug = :slug
        AND (CAST(:seriesId AS UUID) IS NULL OR series_id = CAST(:seriesId AS UUID))
        AND (CAST(:exceptId AS UUID) IS NULL OR id <> CAST(:exceptId AS UUID))
)
SQL,
                [
                    'slug' => $slug,
                    'seriesId' => $seriesId,
                    'exceptId' => $exceptCompetitionId,
                ],
            )
            ->fetchOne();

        return $taken === true;
    }

    public static function isValid(string $slug): bool
    {
        return strlen($slug) <= self::MAX_LENGTH && preg_match(self::PATTERN, $slug) === 1;
    }
}
