<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Value\SearchText;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The server half of the events page search (docs/features/events-page/README.md, "Search"), so a page loaded with
 * `?q=` renders already filtered - with or without JavaScript - exactly as the browser filters it
 * (createQueryMatcher() in assets/events_index.js): every typed word must be in the entry's folded search text `x`,
 * `wjpc` and `ejpc` also find their full names.
 *
 * - `events_search_ids(index, query)`: the ids of the index entries that match, null when nothing was typed
 * - `|search_fold`: SearchText::fold(), for the country sheet's own search (`data-ev-search`)
 */
final class EventsSearchTwigExtension extends AbstractExtension
{
    private const array ALIASES = [
        'wjpc' => 'world jigsaw puzzle championship',
        'ejpc' => 'european jigsaw puzzle championship',
    ];

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('events_search_ids', $this->searchIds(...)),
        ];
    }

    /**
     * @return array<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('search_fold', SearchText::fold(...)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $index
     *
     * @return null|list<int>
     */
    public function searchIds(array $index, string $query): null|array
    {
        $tokens = array_values(array_filter(
            explode(' ', SearchText::fold($query)),
            static fn (string $token): bool => $token !== '',
        ));

        if ($tokens === []) {
            return null;
        }

        $ids = [];

        foreach ($index as $entry) {
            $haystack = $entry['x'] ?? '';
            $id = $entry['id'] ?? null;

            if (!is_string($haystack) || !is_int($id)) {
                continue;
            }

            foreach ($tokens as $token) {
                $alias = self::ALIASES[$token] ?? null;

                if (!str_contains($haystack, $token) && ($alias === null || !str_contains($haystack, $alias))) {
                    continue 2;
                }
            }

            $ids[] = $id;
        }

        return $ids;
    }
}
