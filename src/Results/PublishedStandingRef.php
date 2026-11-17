<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * A published standing of an event as its pages link it (competition toolkit A7) - read with the event itself
 * (GetCompetitionEvents::byId(), CompetitionEvent::$publishedStandings), so the event, edition and round pages link
 * their standings without a statement of their own.
 */
readonly final class PublishedStandingRef
{
    /**
     * @param list<string> $roundIds lower case, as stored
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public RoundCategory $category,
        public array $roundIds,
    ) {
    }

    public function includesRound(string $roundId): bool
    {
        return in_array(strtolower($roundId), $this->roundIds, true);
    }

    /**
     * @return list<self>
     */
    public static function listFromJson(null|string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $items = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($items)) {
            return [];
        }

        $refs = [];

        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || !is_string($item['name'] ?? null) || !is_string($item['slug'] ?? null) || !is_string($item['category'] ?? null)) {
                continue;
            }

            $category = RoundCategory::tryFrom($item['category']);

            if ($category === null) {
                continue;
            }

            $roundIds = is_array($item['round_ids'] ?? null) ? $item['round_ids'] : [];

            $refs[] = new self(
                id: strtolower($item['id']),
                name: $item['name'],
                slug: $item['slug'],
                category: $category,
                roundIds: array_values(array_map(static fn (mixed $id): string => strtolower(is_string($id) ? $id : ''), $roundIds)),
            );
        }

        return $refs;
    }
}
