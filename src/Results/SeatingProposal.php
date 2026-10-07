<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\SeatingSource;

/**
 * Table numbers proposed for every entrant of a round (SeatingProposer) - shown to the organiser first, written only
 * when they apply it (one AssignTableNumbers). docs/features/competitions-management/seating.md
 */
readonly final class SeatingProposal implements \JsonSerializable
{
    public function __construct(
        public string $roundId,
        public SeatingSource $source,
        // What the page offers when the organiser has not picked a source: the best available
        public SeatingSource $defaultSource,
        /** @var array<string, int> source value => how many entrants it has data for (random / by name: all) */
        public array $withDataBySource,
        public bool $slowestFirst,
        // Random only: the draw - the same number gives the same order
        public null|int $randomSeed,
        // MySpeedPuzzling times compare this piece count; assumed when the round has no puzzle yet
        public int $piecesCount,
        public bool $piecesCountAssumed,
        public int $firstTable,
        /** @var list<SeatingProposalRow> by proposed table */
        public array $rows,
    ) {
    }

    public function withData(): int
    {
        return count(array_filter($this->rows, static fn (SeatingProposalRow $row): bool => $row->hasData));
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $sources = [];
        foreach (SeatingSource::cases() as $source) {
            $sources[] = [
                'source' => $source->value,
                'withData' => $this->withDataBySource[$source->value] ?? 0,
            ];
        }

        return [
            'roundId' => $this->roundId,
            'source' => $this->source->value,
            'defaultSource' => $this->defaultSource->value,
            'sources' => $sources,
            'order' => $this->slowestFirst ? 'slowest_first' : 'fastest_first',
            'randomSeed' => $this->randomSeed,
            'piecesCount' => $this->piecesCount,
            'piecesCountAssumed' => $this->piecesCountAssumed,
            'firstTable' => $this->firstTable,
            'total' => count($this->rows),
            'withData' => $this->withData(),
            'withoutData' => count($this->rows) - $this->withData(),
            'rows' => $this->rows,
        ];
    }
}
