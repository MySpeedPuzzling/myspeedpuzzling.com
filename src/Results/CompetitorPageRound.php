<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\EventTime;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * One round on a competitor page (GetCompetitorPage): when it starts in the event's zone, its table, its stopwatch,
 * its puzzles by the public reveal rule (RoundPuzzlesVisibleNow - for every viewer, organisers included) and the
 * entrant with what of the result the viewer may see.
 */
readonly final class CompetitorPageRound implements \JsonSerializable
{
    /**
     * @param list<RoundPuzzleSlot> $slots every puzzle as a place with its label (hidden ones by label only) - shown on
     *     `pick` rounds only, where the choice is by label
     * @param list<VisibleRoundPuzzle> $puzzles the puzzles every viewer may see now
     */
    public function __construct(
        public string $roundId,
        public string $name,
        public RoundCategory $category,
        public EventTime $startsAt,
        public null|int $minutesLimit,
        // The entry's table in this round - null without one (or the round does not use table numbers)
        public null|int $tableNumber,
        // The round the competitor most likely plays now or next (LiveResultsCurrentRound among their rounds)
        public bool $current,
        public RoundStopwatchState $stopwatch,
        public bool $published,
        public bool $isPick,
        public null|int $puzzlesToSolve,
        public array $slots,
        public array $puzzles,
        public null|int $nextRevealAtMs,
        // Null = nobody (an empty table in this round)
        public null|CompetitorPageEntry $entry,
        // The entry is there but hidden from the viewer (the blocklist): the table page shows "—", nothing of it
        public bool $masked = false,
        // Time proposals (B8): the signed-in competitor's own entry with proposals on - the Stop button may show
        public bool $canPropose = false,
        // Their latest proposal in this round (competitor view: no judge identity) - only with canPropose
        public null|TimeProposalView $proposal = null,
        // A guest on a page whose entrant has a linked profile: "Sign in to propose your time" (never says who)
        public bool $signInToPropose = false,
    ) {
    }

    public function topic(): string
    {
        return '/round-stopwatch/' . $this->roundId;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $json = [
            'roundId' => $this->roundId,
            'name' => $this->name,
            'category' => $this->category->value,
            'startsAtMs' => RoundClock::milliseconds($this->startsAt->instant),
            'minutesLimit' => $this->minutesLimit,
            'tableNumber' => $this->tableNumber,
            'current' => $this->current,
            'topic' => $this->topic(),
            'stopwatch' => $this->stopwatch,
            'published' => $this->published,
            // A one-result round lists the puzzles everybody may see; a pick round its places by label, the puzzle
            // named once it is out - never anything of a hidden one but its label
            'puzzles' => $this->isPick
                ? array_map(static fn (RoundPuzzleSlot $slot): array => [
                    'position' => $slot->position,
                    'label' => $slot->label,
                    'puzzle' => $slot->puzzle !== null ? self::puzzle($slot->puzzle) : null,
                ], $this->slots)
                : array_map(self::puzzle(...), $this->puzzles),
            'nextRevealAtMs' => $this->nextRevealAtMs,
            'masked' => $this->masked,
            'entry' => $this->masked ? null : $this->entry,
        ];

        // Only with time proposals on, only on the viewer's own entry - untouched competitions keep their JSON
        if ($this->canPropose) {
            $json['canPropose'] = true;
            $json['proposal'] = $this->proposal;
        }

        if ($this->signInToPropose) {
            $json['signInToPropose'] = true;
        }

        if ($this->isPick) {
            $json['puzzleFormat'] = ['mode' => 'pick', 'toSolve' => $this->puzzlesToSolve];
        }

        return $json;
    }

    /**
     * @return array{puzzleId: string, name: string, brand: null|string, piecesCount: int, imageHidden: bool}
     */
    private static function puzzle(VisibleRoundPuzzle $puzzle): array
    {
        return [
            'puzzleId' => $puzzle->puzzleId,
            'name' => $puzzle->puzzleName,
            'brand' => $puzzle->manufacturerName,
            'piecesCount' => $puzzle->piecesCount,
            'imageHidden' => $puzzle->imageHidden,
        ];
    }
}
