<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * Renders the human-readable mirror of NavigationRegistry (navigation.md in the docs folder): the phone panel and the
 * docked column group by group, the search-only entries and everything linked but not in the Menu, each with its
 * reason. English labels. Written by myspeedpuzzling:navigation:dump-doc, kept identical by NavigationRegistryDocTest;
 * nothing reads the file back.
 */
final readonly class NavigationDocRenderer
{
    private const string LOCALE = 'en';

    public function __construct(
        private NavigationRegistry $registry,
    ) {
    }

    public function render(): string
    {
        $entries = $this->registry->all();
        $lines = [
            '# Navigation registry',
            '',
            '<!-- Generated from src/Navigation/NavigationRegistry.php by `bin/console myspeedpuzzling:navigation:dump-doc`. Do not edit by hand: NavigationRegistryDocTest fails on any difference. -->',
            '',
            'Every destination linked by a navigation surface (bars, rail, the Menu, the account menu, the Help sheet, the footer, the community landing, the chips) is a Menu row, a search-only entry or "not in Menu" with a reason - the placement rulebook of the navigation README (§7). Labels are the English ones; moderator and admin rows stay English in every locale.',
            '',
            sprintf('%d entries: %d phone-panel rows, %d docked-column rows, %d search-only, %d not in the Menu.', count($entries), count($this->registry->rows(MenuSurface::Phone)), count($this->registry->rows(MenuSurface::Dock)), count($this->registry->searchOnly()), count($this->registry->notInMenu())),
            '',
        ];

        array_push($lines, ...$this->surface(MenuSurface::Phone, 'Phone panel (☰ Menu)'));
        array_push($lines, ...$this->surface(MenuSurface::Dock, 'Docked column (desktop)'));

        $lines[] = '## Search-only entries';
        $lines[] = '';
        $lines[] = 'Found by the Menu search ("Pages & settings"), opened under their parent row.';
        $lines[] = '';
        $lines[] = '| Entry | Label | Target | Parent | Audience | Rule | Reason |';
        $lines[] = '|---|---|---|---|---|---|---|';

        foreach ($this->registry->searchOnly() as $entry) {
            $lines[] = $this->row([
                '`' . $entry->id . '`',
                $this->registry->label($entry, self::LOCALE),
                $entry->target->describe(),
                '`' . $entry->phone->parent . '`',
                $entry->visibility->value,
                $entry->rule->value,
                $entry->reason,
            ]);
        }

        $lines[] = '';
        $lines[] = '## Not in the Menu';
        $lines[] = '';
        $lines[] = 'Linked by a navigation surface, but not a Menu destination - each with why.';
        $lines[] = '';
        $lines[] = '| Entry | Label | Target | Audience | Rule | Reason |';
        $lines[] = '|---|---|---|---|---|---|';

        foreach ($this->registry->notInMenu() as $entry) {
            $lines[] = $this->row([
                '`' . $entry->id . '`',
                $this->registry->label($entry, self::LOCALE),
                $entry->target->describe(),
                $entry->visibility->value,
                $entry->rule->value,
                (string) $entry->phone->reason,
            ]);
        }

        $lines[] = '';
        $lines[] = '## Rows not on both surfaces';
        $lines[] = '';
        $lines[] = '| Entry | Phone panel | Docked column |';
        $lines[] = '|---|---|---|';

        foreach ($entries as $entry) {
            if ($entry->isMenuRow() && !($entry->phone->isRow() && $entry->dock->isRow())) {
                $lines[] = $this->row([
                    '`' . $entry->id . '`',
                    $this->placement($entry->phone),
                    $this->placement($entry->dock),
                ]);
            }
        }

        $lines[] = '';
        $lines[] = '## Footer';
        $lines[] = '';
        $lines[] = 'The site footer\'s link columns (FOOT-01, README §4.11) - the same for everybody but the audiences named here; each link goes to its entry\'s own route (guests included). `data-nav` = `footer/<place>/<item>`: the value the link had before FOOT-01, kept so its clicks stay comparable.';
        $lines[] = '';
        $lines[] = '| Column | Pos | Entry | Label | Target | Audience | New | nofollow | data-nav |';
        $lines[] = '|---|---|---|---|---|---|---|---|---|';

        foreach ($this->registry->footer() as $entry) {
            $footer = $entry->footer;
            assert($footer !== null);
            $lines[] = $this->row([
                $footer->column->value,
                (string) $footer->position,
                '`' . $entry->id . '`',
                $this->registry->label($entry, self::LOCALE),
                $entry->kind === RowKind::HelpSheet ? 'the Help sheet' : '`' . $entry->target->route . '`',
                $footer->visibility->value,
                $footer->newBadge ? 'yes' : '',
                $footer->nofollow ? 'yes' : '',
                '`footer/' . $footer->navFor($entry->id) . '`',
            ]);
        }

        $lines[] = '';
        $lines[] = '## Audiences';
        $lines[] = '';
        $lines[] = 'One value per entry; a value that needs several conditions names all of them. Personal rows never include guests. "On the page": the viewer\'s audiences are exposed as `<body data-viewer-audience>` (nav_viewer_audience(), MENU-01; every guest gets `everyone guest`, plus `xp_visible` once XP is launched) - never the role ones, whose entries are rendered for those viewers only.';
        $lines[] = '';
        $lines[] = '| Audience | Who | Guests | On the page |';
        $lines[] = '|---|---|---|---|';

        foreach (NavVisibility::cases() as $visibility) {
            $lines[] = $this->row([$visibility->value, $visibility->docDescription(), $visibility->includesGuests() ? 'yes' : 'no', $visibility->isPageFlag() ? 'yes' : 'no']);
        }

        $lines[] = '';
        $lines[] = '## Rulebook';
        $lines[] = '';

        foreach (RulebookQuestion::cases() as $question) {
            $lines[] = sprintf('- **%s** %s', $question->value, $question->summary());
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return list<string>
     */
    private function surface(MenuSurface $surface, string $title): array
    {
        $lines = ['## ' . $title, ''];
        $currentGroup = null;

        foreach ($this->registry->rows($surface) as $entry) {
            $placement = $entry->placement($surface);

            if ($placement->group !== $currentGroup) {
                if ($currentGroup !== null) {
                    $lines[] = '';
                }

                $currentGroup = $placement->group;
                $lines[] = '### ' . $currentGroup?->docTitle();
                $lines[] = '';
                $lines[] = '| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |';
                $lines[] = '|---|---|---|---|---|---|---|---|---|---|---|';
            }

            $lines[] = $this->row([
                (string) $placement->position,
                '`' . $entry->id . '`',
                $this->registry->label($entry, self::LOCALE) . ($entry->englishOnly ? ' (English)' : ''),
                $entry->target->describe() . ($entry->activeOn !== [] ? '; also active on ' . implode(', ', $entry->activeOn) : ''),
                $entry->visibility->value,
                $entry->kind->value,
                $entry->count->value ?? '',
                $entry->place->value . ($entry->section !== null ? '/' . $entry->section : ''),
                $entry->rule->value,
                $entry->since,
                $entry->reason . ($entry->secondary !== [] ? '. Also: ' . implode('; ', $entry->secondary) : ''),
            ]);
        }

        $lines[] = '';

        return $lines;
    }

    private function placement(MenuPlacement $placement): string
    {
        if ($placement->isRow()) {
            return sprintf('%s / %d', $placement->group?->docTitle(), $placement->position);
        }

        if ($placement->isSearchOnly()) {
            return 'search-only under `' . $placement->parent . '`';
        }

        return 'not in Menu: ' . $placement->reason;
    }

    /**
     * @param list<string> $cells
     */
    private function row(array $cells): string
    {
        return '| ' . implode(' | ', array_map(static fn (string $cell): string => str_replace(['|', "\n"], ['\\|', ' '], $cell), $cells)) . ' |';
    }
}
