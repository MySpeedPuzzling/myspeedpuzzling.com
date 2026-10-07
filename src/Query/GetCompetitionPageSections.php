<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Nette\Utils\Json;
use SpeedPuzzling\Web\Results\PageEntry;
use SpeedPuzzling\Web\Value\PageSectionType;

readonly final class GetCompetitionPageSections
{
    /**
     * Default order of system sections when no custom layout exists.
     */
    public const array SYSTEM_SECTIONS = ['schedule', 'puzzles', 'results', 'registration', 'participants'];

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Ordered page entries for a competition page (standalone event or edition).
     *
     * Order: the competition's own layout (system sections + own custom sections),
     * then inherited series sections (editions cannot edit or reorder those —
     * they are managed on the series page).
     *
     * @return array<PageEntry>
     */
    public function forCompetition(string $competitionId, bool $includeHidden = false): array
    {
        $row = $this->database->executeQuery(
            'SELECT page_layout, series_id FROM competition WHERE id = :id',
            ['id' => $competitionId],
        )->fetchAssociative();

        if ($row === false) {
            return [];
        }

        /** @var array{page_layout: null|string, series_id: null|string} $row */
        $ownSections = $this->loadSections('competition_id', $competitionId);
        $entries = $this->mergeLayout($this->decodeLayout($row['page_layout']), $ownSections, inherited: false);

        if ($row['series_id'] !== null) {
            foreach ($this->loadSections('series_id', $row['series_id']) as $section) {
                $entries[] = $this->customEntry($section, inherited: true);
            }
        }

        if ($includeHidden === false) {
            $entries = array_values(array_filter($entries, static fn (PageEntry $entry): bool => $entry->visible));
        }

        return $entries;
    }

    /**
     * Ordered page entries for a series page.
     *
     * @return array<PageEntry>
     */
    public function forSeries(string $seriesId, bool $includeHidden = false): array
    {
        /** @var false|null|string $layoutJson */
        $layoutJson = $this->database->executeQuery(
            'SELECT page_layout FROM competition_series WHERE id = :id',
            ['id' => $seriesId],
        )->fetchOne();

        $sections = $this->loadSections('series_id', $seriesId);
        // Series pages have no per-competition system sections apart from editions list;
        // layout only orders custom sections around the fixed "editions" system entry
        $entries = $this->mergeLayout(
            $this->decodeLayout($layoutJson === false ? null : $layoutJson),
            $sections,
            inherited: false,
            systemSections: ['editions'],
        );

        if ($includeHidden === false) {
            $entries = array_values(array_filter($entries, static fn (PageEntry $entry): bool => $entry->visible));
        }

        return $entries;
    }

    /**
     * @param array<array{section: string, visible: bool}> $layout
     * @param array<string, PageEntry> $customEntriesByKey
     * @param array<string> $systemSections
     * @return array<PageEntry>
     */
    private function mergeLayout(array $layout, array $customEntriesByKey, bool $inherited, array $systemSections = self::SYSTEM_SECTIONS): array
    {
        $entries = [];
        $seenKeys = [];

        foreach ($layout as $layoutEntry) {
            $key = $layoutEntry['section'];

            if (in_array($key, $systemSections, true)) {
                $entries[] = new PageEntry(key: $key, isSystem: true, visible: $layoutEntry['visible']);
                $seenKeys[] = $key;
            } elseif (isset($customEntriesByKey[$key])) {
                $custom = $customEntriesByKey[$key];
                $entries[] = new PageEntry(
                    key: $custom->key,
                    isSystem: false,
                    visible: $layoutEntry['visible'] && $custom->visible,
                    inherited: $inherited,
                    sectionId: $custom->sectionId,
                    type: $custom->type,
                    title: $custom->title,
                    content: $custom->content,
                );
                $seenKeys[] = $key;
            }
            // Orphaned layout entries (deleted sections) are silently skipped
        }

        // System sections missing from the layout fall back to the default order
        foreach ($systemSections as $key) {
            if (!in_array($key, $seenKeys, true)) {
                $entries[] = new PageEntry(key: $key, isSystem: true, visible: true);
            }
        }

        // Custom sections not referenced in the layout append in position order
        foreach ($customEntriesByKey as $key => $custom) {
            if (!in_array($key, $seenKeys, true)) {
                $entries[] = $custom;
            }
        }

        return $entries;
    }

    /**
     * @return array<string, PageEntry> keyed by "custom:<uuid>", ordered by position
     */
    private function loadSections(string $ownerColumn, string $ownerId): array
    {
        $query = <<<SQL
SELECT id, type, title, content, visible
FROM competition_page_section
WHERE {$ownerColumn} = :ownerId
ORDER BY position ASC, created_at ASC
SQL;

        /** @var array<array{id: string, type: string, title: null|string, content: string, visible: bool|string}> $rows */
        $rows = $this->database->executeQuery($query, ['ownerId' => $ownerId])->fetchAllAssociative();

        $entries = [];

        foreach ($rows as $row) {
            $visible = $row['visible'];
            if (is_string($visible)) {
                $visible = $visible === 't' || $visible === '1' || $visible === 'true';
            }

            /** @var array<string, mixed> $content */
            $content = Json::decode($row['content'], true);

            $entry = new PageEntry(
                key: 'custom:' . $row['id'],
                isSystem: false,
                visible: $visible,
                sectionId: $row['id'],
                type: PageSectionType::from($row['type']),
                title: $row['title'],
                content: $content,
            );

            $entries[$entry->key] = $entry;
        }

        return $entries;
    }

    private function customEntry(PageEntry $entry, bool $inherited): PageEntry
    {
        return new PageEntry(
            key: $entry->key,
            isSystem: false,
            visible: $entry->visible,
            inherited: $inherited,
            sectionId: $entry->sectionId,
            type: $entry->type,
            title: $entry->title,
            content: $entry->content,
        );
    }

    /**
     * @return array<array{section: string, visible: bool}>
     */
    private function decodeLayout(null|string $layoutJson): array
    {
        if ($layoutJson === null || $layoutJson === '') {
            return [];
        }

        /** @var array<array{section: string, visible: bool}> $layout */
        $layout = Json::decode($layoutJson, true);

        return $layout;
    }
}
