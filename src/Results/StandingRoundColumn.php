<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One round of a computed standing, as a column.
 */
readonly final class StandingRoundColumn
{
    public function __construct(
        public string $id,
        public string $name,
        public null|string $slug,
        public null|int $minutesLimit,
        // Its official results are published (results_published_at)
        public bool $published,
        // It takes part in the totals now (organiser: anything recorded in it; public: published)
        public bool $counted,
        // The pieces of a finished round are unknown (a round without puzzles) - no penalty per missing piece there
        public bool $piecesUnknown,
    ) {
    }

    /**
     * @return array{id: string, name: string, slug: null|string, minutesLimit: null|int, published: bool, counted: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'minutesLimit' => $this->minutesLimit,
            'published' => $this->published,
            'counted' => $this->counted,
        ];
    }
}
