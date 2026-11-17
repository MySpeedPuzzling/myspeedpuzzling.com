<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The followed round's official results in a display's state (D-displays-overlays.md §7.2) - present only when the
 * design has a shown results element. Each part is filled only for the element types that ask for it, and before the
 * round's results are published only for a type with an element the organiser switched to `source: live`
 * (GetCompetitionDisplayState): `finished` / `total` null, `top` / `latest` empty otherwise.
 */
readonly final class DisplayResults implements \JsonSerializable
{
    /**
     * @param list<DisplayResultRow> $top best first (leaderboard)
     * @param list<DisplayResultRow> $latest newest first (ticker)
     */
    public function __construct(
        public bool $published,
        public null|int $finished,
        public null|int $total,
        public array $top,
        public array $latest,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'published' => $this->published,
            'finished' => $this->finished,
            'total' => $this->total,
            'top' => $this->top,
            'latest' => $this->latest,
        ];
    }
}
