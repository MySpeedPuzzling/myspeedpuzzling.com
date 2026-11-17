<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionDivision;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundDivision;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionDivisions;
use SpeedPuzzling\Web\Message\SetRoundEntryDivisions;
use SpeedPuzzling\Web\Repository\CompetitionDivisionRepository;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundDivisionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use SpeedPuzzling\Web\Value\EntryDivisionInput;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sets the division of the given entries of one round (shared-puzzles-and-divisions.md A10): a solo entry carries its
 * own, a pair/team entry the team's (members' rows keep none - §3.1). Every item is resolved and checked first - an
 * unknown entry (a removed participant's too), an entry listed twice or a division the round does not use refuses the
 * whole request, nothing changes.
 */
#[AsMessageHandler]
readonly final class SetRoundEntryDivisionsHandler
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private CompetitionRoundDivisionRepository $roundDivisionRepository,
        private CompetitionDivisionRepository $divisionRepository,
        private CompetitionParticipantRoundRepository $participantRoundRepository,
        private CompetitionTeamRepository $teamRepository,
    ) {
    }

    /**
     * @return int how many entries got another division
     * @throws CompetitionRoundNotFound
     * @throws InvalidCompetitionDivisions
     */
    public function __invoke(SetRoundEntryDivisions $message): int
    {
        $round = $this->roundRepository->get($message->roundId);

        // The event whose lock this message holds - never a round of another one
        if ($round->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new CompetitionRoundNotFound();
        }

        $usable = array_map(
            static fn (CompetitionRoundDivision $roundDivision): CompetitionDivision => $roundDivision->division,
            $this->roundDivisionRepository->ofRound($round),
        );

        /** @var list<array{CompetitionParticipantRound|CompetitionTeam, null|CompetitionDivision}> $changes */
        $changes = [];
        $seen = [];
        foreach ($message->entries as $index => $input) {
            $subject = sprintf('entries[%d]', $index);
            $entry = $this->entry($round->id->toString(), $round->category === RoundCategory::Solo, $input, $subject);

            // One entry twice (a pair by its team and by a member, or a typo) - which one would win is a guess
            $ref = $entry->entryRef()->toString();
            if (isset($seen[$ref])) {
                throw new InvalidCompetitionDivisions('duplicate_entry', $subject);
            }

            $seen[$ref] = true;
            $changes[] = [$entry, $this->division($input->division, $usable, $round->competition, $subject)];
        }

        $changed = 0;
        foreach ($changes as [$entry, $division]) {
            if ($entry->division?->id->toString() !== $division?->id->toString()) {
                $entry->changeDivision($division);
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * @throws InvalidCompetitionDivisions
     */
    private function entry(string $roundId, bool $solo, EntryDivisionInput $input, string $subject): CompetitionParticipantRound|CompetitionTeam
    {
        if ($input->teamId !== null) {
            $team = $solo ? null : $this->teamRepository->find($input->teamId);

            return $team !== null && $team->round->id->toString() === $roundId ? $team : throw new InvalidCompetitionDivisions('unknown_entry', $subject);
        }

        $participantRound = $input->participantId !== null ? $this->participantRoundRepository->findOfParticipantInRound($input->participantId, $roundId) : null;

        // A removed participant's entry is not an entry any more (the pages hide it)
        if ($participantRound !== null && $participantRound->participant->isDeleted()) {
            $participantRound = null;
        }

        $entry = $solo ? $participantRound : $participantRound?->team;

        return $entry ?? throw new InvalidCompetitionDivisions('unknown_entry', $subject);
    }

    /**
     * The division asked for by its id, name or short label - one the round uses.
     *
     * @param list<CompetitionDivision> $usable
     * @throws InvalidCompetitionDivisions
     */
    private function division(null|string $asked, array $usable, Competition $competition, string $subject): null|CompetitionDivision
    {
        if ($asked === null || trim($asked) === '') {
            return null;
        }

        $matches = static function (CompetitionDivision $division) use ($asked): bool {
            $wanted = mb_strtolower(trim($asked));

            return strtolower($division->id->toString()) === $wanted
                || mb_strtolower($division->name) === $wanted
                || ($division->shortName !== null && mb_strtolower($division->shortName) === $wanted);
        };

        foreach ($usable as $division) {
            if ($matches($division)) {
                return $division;
            }
        }

        $ofEvent = array_any($this->divisionRepository->ofCompetition($competition), $matches);

        throw new InvalidCompetitionDivisions($ofEvent ? 'not_in_round' : 'unknown_division', $subject . ': ' . $asked);
    }
}
