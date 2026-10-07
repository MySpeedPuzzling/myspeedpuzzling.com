<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\DBAL\Connection;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * The URL slug of a competition (`/en/events/{slug}`) or of a series (`/en/series/{slug}`): generated from the name
 * once, or chosen explicitly (the edit forms' "URL" field, the internal API's `slug`). A rename never changes it.
 *
 * The event page looks a competition up by its slug alone, so a standalone competition's slug is unique across every
 * competition. An edition's slug is only unique within its series (`competition (series_id, slug)`), because its
 * address is `/en/series/{seriesSlug}/{editionSlug}`. A series' slug is unique among series (`competition_series.slug`).
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
     * What a person typed as a URL, made into a slug the way names are: "My New URL" → "my-new-url". Can be empty
     * (nothing sluggable typed) - check it with isValid().
     */
    public function normalize(string $input): string
    {
        $input = trim($input);

        // A pasted address ("myspeedpuzzling.com/en/events/wjpc-2026/") means its last part, not all of it
        if (str_contains($input, '/')) {
            $segments = array_values(array_filter(explode('/', (string) preg_replace('/[?#].*$/', '', $input)), static fn (string $segment): bool => $segment !== ''));
            $input = $segments === [] ? '' : rawurldecode($segments[array_key_last($segments)]);
        }

        // Transliterated the same whatever the page language ("ü" → "u", not "ue" on a German page)
        return strtolower((string) $this->slugger->slug(strtolower($input), '-', 'en'));
    }

    /**
     * Whether another series holds the slug.
     */
    public function isSeriesSlugTaken(string $slug, null|string $exceptSeriesId = null): bool
    {
        $taken = $this->database
            ->executeQuery(
                <<<SQL
SELECT EXISTS (
    SELECT 1
    FROM competition_series
    WHERE slug = :slug
        AND (CAST(:exceptId AS UUID) IS NULL OR id <> CAST(:exceptId AS UUID))
)
SQL,
                [
                    'slug' => $slug,
                    'exceptId' => $exceptSeriesId,
                ],
            )
            ->fetchOne();

        return $taken === true;
    }

    /**
     * Whether another competition holds the slug: any competition for a standalone one (`$seriesId` null), the
     * editions of the same series or a standalone event for an edition - `/en/events/{slug}` must keep reaching the
     * standalone event (an edition reached there is redirected to its series URL).
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
        AND (CAST(:seriesId AS UUID) IS NULL OR series_id = CAST(:seriesId AS UUID) OR series_id IS NULL)
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
