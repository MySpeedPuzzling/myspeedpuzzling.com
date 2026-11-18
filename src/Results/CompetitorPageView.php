<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CompetitionTagKind;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * A public competitor page (GetCompetitorPage, docs/features/competitions-management/competitor-pages.md) - what one
 * scanned competition tag stands for: a person, a pair/team line-up, a table or one round's entry, with their rounds.
 * The same view feeds the page and its `competitor_state` JSON.
 */
readonly final class CompetitorPageView implements \JsonSerializable
{
    /**
     * @param list<PublishedRoundEntrant> $entrants who the page is about (the header) - the person, the line-up's or the
     *     entry's people; empty on a table page (whoever sits there changes by the round)
     * @param list<CompetitorPageRound> $rounds in schedule order; a table page puts the current round first
     * @param list<ParticipantClaimCandidate> $claimable
     */
    public function __construct(
        public string $competitionId,
        public CompetitionReference $competition,
        public CompetitionTagKind $kind,
        public string $tagId,
        public bool $isTeam,
        public null|string $teamName,
        public array $entrants,
        // The viewer is the competitor (the person, a member of the line-up or of the entry)
        public bool $viewerIsEntrant,
        // The organiser shows confirmed results before publishing (competitor_pages_live_results)
        public bool $liveResults,
        // Not publicly visible (a draft, waiting for approval, rejected) - only its team sees the page
        public bool $isHidden,
        public array $rounds,
        public DateTimeImmutable $serverNow,
        // The event takes time proposals from competitors (competitor pages on, in person) - B8
        public bool $timeProposals = false,
        // "This is me" (participant-linking.md §6.4): the header's people nobody is linked to, for a signed-in viewer who
        // is not on this person or line-up - server HTML only, never in the JSON
        public array $claimable = [],
    ) {
    }

    public function tableNumber(): null|int
    {
        return $this->kind === CompetitionTagKind::Table ? (int) $this->tagId : null;
    }

    public function currentRound(): null|CompetitorPageRound
    {
        foreach ($this->rounds as $round) {
            if ($round->current) {
                return $round;
            }
        }

        return null;
    }

    /**
     * A round where the viewer may propose their own time - the page then carries the Stop button's endpoints
     */
    public function anyCanPropose(): bool
    {
        return array_any($this->rounds, static fn (CompetitorPageRound $round): bool => $round->canPropose);
    }

    /**
     * @return list<string>
     */
    public function topics(): array
    {
        return array_map(static fn (CompetitorPageRound $round): string => $round->topic(), $this->rounds);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'serverNowMs' => RoundClock::milliseconds($this->serverNow),
            'kind' => $this->kind->value,
            'id' => $this->tagId,
            'rounds' => $this->rounds,
        ];
    }
}
