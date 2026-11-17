<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CompetitorResultState;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * The entrant of one round on a competitor page (GetCompetitorPage): the person of a solo round or the pair/team, by
 * the organiser's names (masked like the public round page - OfficialEntrantsView::entrant()), and the result as far
 * as the viewer may see it (CompetitorResultState): null while nothing may be shown.
 */
readonly final class CompetitorPageEntry implements \JsonSerializable
{
    /**
     * @param list<PublishedRoundEntrant> $entrants the person, or the members of the pair/team; empty for a person in a
     *     pair/team round without a pair/team yet is never the case - they are its one entrant
     * @param list<PublishedEntryPuzzle> $puzzleChips a `pick` round's chosen puzzles (public reveal rule), only with a
     *     result shown
     */
    public function __construct(
        public RoundEntryRef $ref,
        public bool $isTeam,
        public null|string $teamName,
        public array $entrants,
        // The viewer is linked to it: the person, or a member of the pair/team
        public bool $isViewers,
        public null|RoundEntryResult $result,
        public null|CompetitorResultState $resultState,
        // The official place - published rounds only
        public null|int $rank,
        // Marked qualified - published rounds only
        public bool $qualified,
        public array $puzzleChips,
        // The pieces of what the entry solved - "479 / 1000 pcs" - when known publicly
        public null|int $piecesCount,
        // A person put into a pair/team round who has no pair/team yet
        public bool $unpaired = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $json = [
            'isTeam' => $this->isTeam,
            'teamName' => $this->teamName,
            'entrants' => array_map(static fn (PublishedRoundEntrant $entrant): array => [
                'name' => $entrant->playerName,
                'country' => $entrant->playerCountry?->name,
            ], $this->entrants),
            'own' => $this->isViewers,
            'unpaired' => $this->unpaired,
            'result' => $this->result === null ? null : [
                'state' => $this->resultState?->value,
                'seconds' => $this->result->seconds,
                'piecesPlaced' => $this->result->piecesPlaced,
                'piecesCount' => $this->piecesCount,
                'didNotStart' => $this->result->didNotStart,
                'rank' => $this->rank,
                'qualified' => $this->qualified,
                'puzzles' => array_map(static fn (PublishedEntryPuzzle $chip): array => [
                    'position' => $chip->position,
                    'label' => $chip->label,
                    'name' => $chip->puzzle?->puzzleName,
                    'done' => $chip->done,
                    'atSeconds' => $chip->atSeconds,
                    'piecesPlaced' => $chip->piecesPlaced,
                ], $this->puzzleChips),
            ],
        ];

        // Which entry it is - only the viewer's own (what their own page acts on later, B-qr-competitor-page.md §4.4)
        if ($this->isViewers) {
            $json['entry'] = $this->ref->toString();
        }

        return $json;
    }
}
