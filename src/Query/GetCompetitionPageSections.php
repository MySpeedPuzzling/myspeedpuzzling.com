<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Nette\Utils\Json;
use SpeedPuzzling\Web\Results\PageSection;
use SpeedPuzzling\Web\Value\PageSectionType;

/**
 * Organiser-written sections of an event, edition or series page (docs/features/competitions-management/public-page.md).
 *
 * The public pages call it only when their own row says a section shows (CompetitionEvent::$hasPageSections,
 * CompetitionSeriesOverview::$hasPageSections - the same SQL rule as here, in the statement the page runs anyway), so a
 * page without sections never runs it.
 */
readonly final class GetCompetitionPageSections
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Section `s` shows on the page of competition `c`: visible, the competition's own or its series', a venue only when
     * the event is in person.
     */
    public static function sqlShownOnCompetitionPage(string $sectionAlias, string $competitionAlias): string
    {
        return "{$sectionAlias}.visible = true"
            . " AND ({$sectionAlias}.competition_id = {$competitionAlias}.id OR {$sectionAlias}.series_id = {$competitionAlias}.series_id)"
            . " AND ({$competitionAlias}.is_online = false OR {$sectionAlias}.type <> 'venue')";
    }

    /**
     * Section `s` shows on the page of series `cs`.
     */
    public static function sqlShownOnSeriesPage(string $sectionAlias, string $seriesAlias): string
    {
        return "{$sectionAlias}.visible = true"
            . " AND {$sectionAlias}.series_id = {$seriesAlias}.id"
            . " AND ({$seriesAlias}.is_online = false OR {$sectionAlias}.type <> 'venue')";
    }

    /**
     * What an event or edition page shows: its own sections, then (an edition) its series' sections, each in page order.
     *
     * @return list<PageSection>
     */
    public function forCompetitionPage(string $competitionId): array
    {
        $shown = self::sqlShownOnCompetitionPage('s', 'c');

        return $this->fetch(<<<SQL
SELECT s.id, s.type, s.title, s.content, s.visible, (s.competition_id IS NULL) AS inherited
FROM competition c
JOIN competition_page_section s ON {$shown}
WHERE c.id = :ownerId
ORDER BY (s.competition_id IS NULL), s.position, s.created_at, s.id
SQL, $competitionId);
    }

    /**
     * @return list<PageSection>
     */
    public function forSeriesPage(string $seriesId): array
    {
        $shown = self::sqlShownOnSeriesPage('s', 'cs');

        return $this->fetch(<<<SQL
SELECT s.id, s.type, s.title, s.content, s.visible, false AS inherited
FROM competition_series cs
JOIN competition_page_section s ON {$shown}
WHERE cs.id = :ownerId
ORDER BY s.position, s.created_at, s.id
SQL, $seriesId);
    }

    /**
     * The page editor of an event or edition: every own section (hidden ones too), then the series' sections that show
     * on this edition's page - those are changed on the series' page.
     *
     * @return list<PageSection>
     */
    public function forCompetitionEditor(string $competitionId): array
    {
        $shown = self::sqlShownOnCompetitionPage('s', 'c');

        return $this->fetch(<<<SQL
SELECT s.id, s.type, s.title, s.content, s.visible, (s.competition_id IS NULL) AS inherited
FROM competition c
JOIN competition_page_section s ON s.competition_id = c.id OR (s.competition_id IS NULL AND {$shown})
WHERE c.id = :ownerId
ORDER BY (s.competition_id IS NULL), s.position, s.created_at, s.id
SQL, $competitionId);
    }

    /**
     * @return list<PageSection>
     */
    public function forSeriesEditor(string $seriesId): array
    {
        return $this->fetch(<<<SQL
SELECT s.id, s.type, s.title, s.content, s.visible, false AS inherited
FROM competition_page_section s
WHERE s.series_id = :ownerId
ORDER BY s.position, s.created_at, s.id
SQL, $seriesId);
    }

    /**
     * @return list<PageSection>
     */
    private function fetch(string $query, string $ownerId): array
    {
        /** @var list<array{id: string, type: string, title: null|string, content: string, visible: bool, inherited: bool}> $rows */
        $rows = $this->database->executeQuery($query, ['ownerId' => $ownerId])->fetchAllAssociative();

        return array_map(static function (array $row): PageSection {
            $type = PageSectionType::from($row['type']);

            /** @var array<string, mixed> $content */
            $content = Json::decode($row['content'], true);

            return new PageSection(
                id: $row['id'],
                type: $type,
                title: $row['title'],
                content: $type === PageSectionType::Links ? self::withTrackedLinks($content) : $content,
                visible: $row['visible'],
                inherited: $row['inherited'],
            );
        }, $rows);
    }

    /**
     * Like every external link of an event page, a links section tells the target where the visitor came from.
     *
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private static function withTrackedLinks(array $content): array
    {
        $links = $content['links'] ?? null;

        if (!is_array($links)) {
            return $content;
        }

        $tracked = [];

        foreach ($links as $link) {
            if (!is_array($link) || !is_string($link['url'] ?? null)) {
                continue;
            }

            $parts = explode('#', $link['url'], 2);
            $link['href'] = $parts[0]
                . (str_contains($parts[0], '?') ? '&' : '?') . 'utm_source=myspeedpuzzling'
                . (isset($parts[1]) ? '#' . $parts[1] : '');
            $tracked[] = $link;
        }

        $content['links'] = $tracked;

        return $content;
    }
}
