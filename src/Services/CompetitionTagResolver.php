<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetCompetitionTagEntries;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Results\CompetitionTagEntry;
use SpeedPuzzling\Web\Results\CompetitionTagTarget;
use SpeedPuzzling\Web\Results\RoundResultsOverview;
use SpeedPuzzling\Web\Value\CompetitionTag;
use SpeedPuzzling\Web\Value\CompetitionTagKind;

/**
 * Which entry in which round a scanned competition tag means right now (docs/features/competitions-management/toolkit/
 * B-qr-competitor-page.md §3.3, R-review-and-plan.md §1.3). The dynamic kinds - a person, a line-up, a table - take
 * the current round (LiveResultsCurrentRound) among the rounds the tag plays in, else among all the event's rounds; a
 * round-entry tag always means its own round, whatever runs now. Stateless (worker mode, R §2.5).
 */
final readonly class CompetitionTagResolver
{
    public function __construct(
        private GetCompetitionTagEntries $getCompetitionTagEntries,
        private GetRoundResultsOverview $getRoundResultsOverview,
        private ClockInterface $clock,
    ) {
    }

    public function resolve(string $competitionId, CompetitionTag $tag): CompetitionTagTarget
    {
        $entries = $this->entriesOf($competitionId, $tag);
        $rounds = $this->getRoundResultsOverview->forCompetition($competitionId);
        $now = $this->clock->now();
        $roundIds = array_map(static fn (CompetitionTagEntry $entry): string => $entry->roundId, $entries ?? []);
        $theirs = array_values(array_filter(
            $rounds,
            static fn (RoundResultsOverview $round): bool => in_array($round->roundId, $roundIds, true),
        ));

        // A round-entry tag is static: its own round, never the one running now
        $round = $tag->kind === CompetitionTagKind::Entry && $theirs !== []
            ? $theirs[0]
            : LiveResultsCurrentRound::pick($theirs, $now) ?? LiveResultsCurrentRound::pick($rounds, $now);

        $entry = null;
        foreach ($entries ?? [] as $candidate) {
            if ($round !== null && $candidate->roundId === $round->roundId) {
                $entry = $candidate->entry;
                break;
            }
        }

        return new CompetitionTagTarget(
            tag: $tag,
            known: $entries !== null && ($tag->kind === CompetitionTagKind::Participant || $entries !== []),
            round: $round,
            entry: $entry,
        );
    }

    /**
     * Whether the tag stands for something of the event without picking a round - for the public landing, which opens
     * the competitor page of the tag itself (a table always has one: who sits there changes by the round).
     */
    public function knows(string $competitionId, CompetitionTag $tag): bool
    {
        if ($tag->kind === CompetitionTagKind::Table) {
            return true;
        }

        $entries = $this->entriesOf($competitionId, $tag);

        return $entries !== null && ($tag->kind === CompetitionTagKind::Participant || $entries !== []);
    }

    /**
     * @return null|list<CompetitionTagEntry> null = not a participant of the event (participant tags only)
     */
    private function entriesOf(string $competitionId, CompetitionTag $tag): null|array
    {
        return match ($tag->kind) {
            CompetitionTagKind::Participant => $this->getCompetitionTagEntries->ofParticipant($competitionId, $tag->id),
            CompetitionTagKind::Lineup => $this->getCompetitionTagEntries->ofLineup($competitionId, $tag->id),
            CompetitionTagKind::Table => $this->getCompetitionTagEntries->atTable($competitionId, (int) $tag->tableNumber()),
            CompetitionTagKind::Entry => $this->entryOf($competitionId, $tag->id),
        };
    }

    /**
     * @return list<CompetitionTagEntry>
     */
    private function entryOf(string $competitionId, string $entryId): array
    {
        $entry = $this->getCompetitionTagEntries->entry($competitionId, $entryId);

        return $entry !== null ? [$entry] : [];
    }
}
