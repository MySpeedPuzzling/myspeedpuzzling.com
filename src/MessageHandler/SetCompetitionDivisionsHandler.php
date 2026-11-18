<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionDivision;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundDivision;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Exceptions\DivisionsInUse;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionDivisions;
use SpeedPuzzling\Web\Message\SetCompetitionDivisions;
use SpeedPuzzling\Web\Repository\CompetitionDivisionRepository;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundDivisionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionStandingRepository;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Saves the event's divisions and each round's subset (shared-puzzles-and-divisions.md §3.3, D1). Everything is checked
 * before anything changes (a refusal leaves the unit of work untouched): the list (≤ 12, names unique per event without
 * regard to case, ids of this event or new), the rounds (of this event, divisions of the new list), then the entries
 * that would lose their division - refused (DivisionsInUse) unless confirmed, then cleared one by one (entities, the
 * counts are small). Entries of removed participants lose theirs silently and are not counted. A division a standing is
 * limited to (real events L2a) is never removed - from the list or from a round the standing counts (`used_by_standing`).
 */
#[AsMessageHandler]
readonly final class SetCompetitionDivisionsHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private CompetitionRoundRepository $roundRepository,
        private CompetitionDivisionRepository $divisionRepository,
        private CompetitionRoundDivisionRepository $roundDivisionRepository,
        private CompetitionParticipantRoundRepository $participantRoundRepository,
        private CompetitionTeamRepository $teamRepository,
        private CompetitionStandingRepository $standingRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<string> ids of the rounds whose divisions or entries changed
     * @throws InvalidCompetitionDivisions
     * @throws DivisionsInUse
     */
    public function __invoke(SetCompetitionDivisions $message): array
    {
        $competition = $this->competitionRepository->get($message->competitionId);

        /** @var array<string, CompetitionDivision> $existing */
        $existing = [];
        foreach ($this->divisionRepository->ofCompetition($competition) as $division) {
            $existing[strtolower($division->id->toString())] = $division;
        }

        if (count($message->divisions) > CompetitionDivision::MAX_PER_COMPETITION) {
            throw new InvalidCompetitionDivisions('too_many');
        }

        // The list: [id => [name, short name]] in its order
        $wantedDivisions = [];
        $names = [];
        foreach ($message->divisions as $input) {
            $id = strtolower($input->id);

            if (isset($wantedDivisions[$id])) {
                throw new InvalidCompetitionDivisions('duplicate_id', $input->id);
            }

            if (!isset($existing[$id]) && (!Uuid::isValid($id) || $this->divisionRepository->find($id) !== null)) {
                throw new InvalidCompetitionDivisions('unknown_division', $input->id);
            }

            try {
                $name = CompetitionDivision::cleanName($input->name);
            } catch (InvalidArgumentException) {
                throw new InvalidCompetitionDivisions('invalid_name', $input->name);
            }

            try {
                $shortName = CompetitionDivision::cleanShortName($input->shortName);
            } catch (InvalidArgumentException) {
                throw new InvalidCompetitionDivisions('invalid_short_name', $input->shortName);
            }

            $key = mb_strtolower($name);
            if (isset($names[$key])) {
                throw new InvalidCompetitionDivisions('duplicate_name', $name);
            }

            $names[$key] = true;
            $wantedDivisions[$id] = [$name, $shortName];
        }

        $rounds = [];
        foreach ($this->roundRepository->ofCompetition($competition) as $round) {
            $rounds[strtolower($round->id->toString())] = $round;
        }

        /** @var array<string, array<string, true>> $wantedSubsets round id => division ids */
        $wantedSubsets = [];
        foreach ($message->rounds as $roundId => $divisionIds) {
            $roundId = strtolower((string) $roundId);

            if (!isset($rounds[$roundId])) {
                throw new InvalidCompetitionDivisions('unknown_round', $roundId);
            }

            foreach ($divisionIds as $divisionId) {
                $divisionId = strtolower($divisionId);

                if (!isset($wantedDivisions[$divisionId])) {
                    throw new InvalidCompetitionDivisions('unknown_division', $divisionId);
                }

                $wantedSubsets[$roundId][$divisionId] = true;
            }
        }

        // A standing limited to a division (real events L2a) keeps it, in every round it counts - edit or delete the
        // standing first (its public page would silently become another ranking)
        foreach ($this->standingRepository->ofCompetition($competition) as $standing) {
            $divisionId = $standing->division !== null ? strtolower($standing->division->id->toString()) : null;

            if ($divisionId === null) {
                continue;
            }

            if (!isset($wantedDivisions[$divisionId])) {
                throw new InvalidCompetitionDivisions('used_by_standing', $standing->name);
            }

            foreach ($standing->roundIds() as $roundId) {
                if (isset($rounds[$roundId]) && !isset($wantedSubsets[$roundId][$divisionId])) {
                    throw new InvalidCompetitionDivisions('used_by_standing', $standing->name);
                }
            }
        }

        // Entries whose division goes away - removed from the list, or from their round
        $keeps = static function (CompetitionParticipantRound|CompetitionTeam $entry) use ($wantedSubsets): bool {
            $divisionId = strtolower((string) $entry->division?->id->toString());

            return isset($wantedSubsets[strtolower($entry->round->id->toString())][$divisionId]);
        };
        $losing = array_values(array_filter(
            [...$this->participantRoundRepository->withDivisionInCompetition($competition), ...$this->teamRepository->withDivisionInCompetition($competition)],
            static fn (CompetitionParticipantRound|CompetitionTeam $entry): bool => $keeps($entry) === false,
        ));
        $losingCount = count(array_filter(
            $losing,
            static fn (CompetitionParticipantRound|CompetitionTeam $entry): bool => $entry instanceof CompetitionTeam || $entry->participant->isDeleted() === false,
        ));

        if ($losingCount > 0 && $message->confirmClearing === false) {
            throw new DivisionsInUse($losingCount);
        }

        // Every check passed - from here on, only changes
        $changedRounds = [];

        foreach ($losing as $entry) {
            $entry->changeDivision(null);
            $changedRounds[strtolower($entry->round->id->toString())] = true;
        }

        /** @var array<string, CompetitionDivision> $divisions */
        $divisions = [];
        $relabelled = [];
        $position = 0;
        foreach ($wantedDivisions as $id => [$name, $shortName]) {
            $division = $existing[$id] ?? null;

            if ($division === null) {
                $division = new CompetitionDivision(Uuid::fromString($id), $competition, $name, $this->clock->now(), $shortName, $position);
                $this->divisionRepository->save($division);
            } elseif ($division->name !== $name || $division->shortName !== $shortName || $division->position !== $position) {
                $division->change($name, $shortName, $position);
                $relabelled[$id] = true;
            }

            $divisions[$id] = $division;
            $position++;
        }

        // The subsets: rows no longer wanted go, missing ones come
        /** @var array<string, array<string, true>> $currentSubsets */
        $currentSubsets = [];
        foreach ($this->roundDivisionRepository->ofCompetition($competition) as $roundDivision) {
            $roundId = strtolower($roundDivision->round->id->toString());
            $divisionId = strtolower($roundDivision->division->id->toString());

            if (isset($relabelled[$divisionId])) {
                $changedRounds[$roundId] = true;
            }

            if (isset($wantedSubsets[$roundId][$divisionId])) {
                $currentSubsets[$roundId][$divisionId] = true;

                continue;
            }

            $this->roundDivisionRepository->delete($roundDivision);
            $changedRounds[$roundId] = true;
        }

        foreach ($wantedSubsets as $roundId => $divisionIds) {
            foreach (array_keys($divisionIds) as $divisionId) {
                if (!isset($currentSubsets[$roundId][$divisionId])) {
                    $this->roundDivisionRepository->save(new CompetitionRoundDivision(Uuid::uuid7(), $rounds[$roundId], $divisions[$divisionId]));
                    $changedRounds[$roundId] = true;
                }
            }
        }

        foreach ($existing as $id => $division) {
            if (!isset($wantedDivisions[$id])) {
                $this->divisionRepository->delete($division);
            }
        }

        return array_map(strval(...), array_keys($changedRounds));
    }
}
