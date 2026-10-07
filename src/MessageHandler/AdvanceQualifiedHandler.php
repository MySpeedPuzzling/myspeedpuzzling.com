<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Exceptions\AdvancementPlanChanged;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\InvalidAdvancement;
use SpeedPuzzling\Web\Message\AdvanceQualified;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use SpeedPuzzling\Web\Results\AdvancementAssignment;
use SpeedPuzzling\Web\Results\AdvancementPlan;
use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Services\AdvancementSeeding;
use SpeedPuzzling\Web\Value\AdvanceDistribution;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Plans under the event's participants lock - always, also when applying - and writes only the plan the organiser saw
 * (`planHash`). Reads go through GetRoundResultEntries on the transaction's own connection, so the plan is the state
 * the write lands on.
 *
 * Nobody ends up in two target rounds or twice in one: the people already in any target round and the people already
 * planned are skipped, best seed first - a person qualified in two pairs/teams goes with the better one.
 */
#[AsMessageHandler]
readonly final class AdvanceQualifiedHandler
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private GetRoundResultEntries $getRoundResultEntries,
        private CompetitionParticipantRepository $participantRepository,
        private CompetitionParticipantRoundRepository $participantRoundRepository,
        private CompetitionTeamRepository $teamRepository,
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    // "Best of each country": at most this many per country
    public const int MAX_PER_COUNTRY = 99;

    /**
     * @throws CompetitionRoundNotFound
     * @throws InvalidAdvancement
     * @throws AdvancementPlanChanged
     */
    public function __invoke(AdvanceQualified $message): AdvancementPlan
    {
        $sources = $this->rounds($message->sourceRoundIds, $message->competitionId);
        $targets = $this->rounds($message->targetRoundIds, $message->competitionId);
        $this->validate($message, $sources, $targets);

        $category = array_values($sources)[0]->category;

        $entriesBySource = [];
        $piecesCountBySource = [];
        foreach ($sources as $roundId => $round) {
            $entriesBySource[$roundId] = $this->getRoundResultEntries->forRound($roundId);
            $piecesCountBySource[$roundId] = $round->singlePuzzlePiecesCount();
        }

        // "Best of each country" over all the source rounds: entries not marked qualified that the rule takes in
        $byCountryRule = [];
        $withoutCountry = [];
        if ($message->bestOfEachCountry !== null) {
            [$byCountryRule, $withoutCountry] = self::bestOfEachCountry($entriesBySource, $piecesCountBySource, $message->bestOfEachCountry);
        }

        $seeded = AdvancementSeeding::seed(
            $entriesBySource,
            $piecesCountBySource,
            static fn (RoundResultEntry $entry): bool => $entry->isQualified() || isset($byCountryRule[$entry->ref->toString()]),
        );

        $targetEntries = [];
        foreach (array_keys($targets) as $roundId) {
            $targetEntries[$roundId] = $this->getRoundResultEntries->forRound($roundId);
        }

        // Everybody already in a target round - alone, in a team, or without a team yet in a pair/team round
        /** @var list<string> $participantIdsInTargets */
        $participantIdsInTargets = $this->database->fetchFirstColumn(
            'SELECT participant_id FROM competition_participant_round WHERE round_id IN (:roundIds)',
            ['roundIds' => array_keys($targets)],
            ['roundIds' => ArrayParameterType::STRING],
        );
        $participantsInTargets = array_fill_keys($participantIdsInTargets, true);

        $teamsInTargets = [];
        foreach ($targetEntries as $entries) {
            foreach ($entries as $entry) {
                $teamsInTargets[self::identity($entry)] = true;
            }
        }

        $toPlace = [];
        $skipped = [];
        $seen = [];
        // People planned into a target round so far - a person in two qualified pairs/teams goes once, with the better
        $planned = [];
        foreach ($seeded as $seededEntry) {
            $entry = $seededEntry->entry;
            $identity = self::identity($entry);
            $reason = null;

            if (isset($seen[$identity])) {
                $reason = 'qualified_twice';
            } elseif ($category === RoundCategory::Solo) {
                $reason = isset($participantsInTargets[(string) $entry->participantId]) ? 'already_in_target' : null;
            } elseif (isset($teamsInTargets[$identity])) {
                $reason = 'already_in_target';
            } else {
                foreach ($entry->participantIds() as $participantId) {
                    if (isset($participantsInTargets[$participantId])) {
                        $reason = 'member_already_in_target';
                    } elseif ($reason === null && isset($planned[$participantId])) {
                        $reason = 'member_already_planned';
                    }
                }
            }

            $seen[$identity] = true;

            if ($reason !== null) {
                $skipped[] = [
                    'seed' => $seededEntry->seed,
                    'sourceRoundId' => $seededEntry->sourceRoundId,
                    'entry' => $entry,
                    'reason' => $reason,
                    'byCountryRule' => isset($byCountryRule[$entry->ref->toString()]),
                ];

                continue;
            }

            foreach ($entry->participantIds() as $participantId) {
                $planned[$participantId] = true;
            }

            $toPlace[] = $seededEntry;
        }

        $targetIds = array_keys($targets);
        $assignments = [];
        foreach ($toPlace as $index => $seededEntry) {
            $assignments[] = new AdvancementAssignment(
                seed: $seededEntry->seed,
                sourceRoundId: $seededEntry->sourceRoundId,
                targetRoundId: match ($message->distribution) {
                    AdvanceDistribution::Single => $targetIds[0],
                    AdvanceDistribution::Balanced => $targetIds[self::serpentine($index, count($targetIds))],
                    AdvanceDistribution::BySource => self::targetMap($message)[$seededEntry->sourceRoundId],
                },
                entry: $seededEntry->entry,
                byCountryRule: isset($byCountryRule[$seededEntry->entry->ref->toString()]),
            );
        }

        $planHash = self::hash($message, $assignments, $skipped, $targetEntries, $byCountryRule);

        if ($message->dryRun === false) {
            if ($message->planHash === null || hash_equals($planHash, $message->planHash) === false) {
                throw new AdvancementPlanChanged();
            }

            // The country rule's entries are qualified now - a mark in their own round, like the organiser's own marks
            $now = $this->clock->now();
            foreach (array_keys($byCountryRule) as $ref) {
                $this->roundEntry($ref)?->markQualified($now);
            }

            $participantIds = [];
            foreach ($assignments as $assignment) {
                foreach ($assignment->entry->participantIds() as $participantId) {
                    $participantIds[] = $participantId;
                }
            }
            $participants = $this->participantRepository->findByIds($participantIds);

            $assignments = array_map(fn (AdvancementAssignment $assignment): AdvancementAssignment => new AdvancementAssignment(
                seed: $assignment->seed,
                sourceRoundId: $assignment->sourceRoundId,
                targetRoundId: $assignment->targetRoundId,
                entry: $assignment->entry,
                createdEntryRef: $this->advance($assignment->entry, $targets[$assignment->targetRoundId], $participants),
                byCountryRule: $assignment->byCountryRule,
            ), $assignments);
        }

        $summary = [];
        foreach ($targets as $roundId => $round) {
            $before = count($targetEntries[$roundId]);
            $added = count(array_filter($assignments, static fn (AdvancementAssignment $assignment): bool => $assignment->targetRoundId === $roundId));
            $summary[] = ['roundId' => $roundId, 'name' => $round->name, 'entriesBefore' => $before, 'entriesAfter' => $before + $added];
        }

        return new AdvancementPlan(
            planHash: $planHash,
            applied: $message->dryRun === false,
            assignments: $assignments,
            skipped: $skipped,
            targets: $summary,
            bestOfEachCountry: $message->bestOfEachCountry,
            markedByCountryRule: array_map(
                static fn (string $ref, string $sourceRoundId): array => ['entry' => $ref, 'sourceRoundId' => $sourceRoundId],
                array_keys($byCountryRule),
                array_values($byCountryRule),
            ),
            withoutCountry: $withoutCountry,
        );
    }

    /**
     * @param list<string> $roundIds
     * @return array<string, CompetitionRound> lower-case id => round, in the given order
     * @throws CompetitionRoundNotFound
     */
    private function rounds(array $roundIds, string $competitionId): array
    {
        $rounds = [];

        foreach ($roundIds as $roundId) {
            $round = $this->roundRepository->get($roundId);

            if ($round->competition->id->toString() !== strtolower($competitionId)) {
                throw new CompetitionRoundNotFound();
            }

            $rounds[$round->id->toString()] = $round;
        }

        return $rounds;
    }

    /**
     * @param array<string, CompetitionRound> $sources
     * @param array<string, CompetitionRound> $targets
     * @throws InvalidAdvancement
     */
    private function validate(AdvanceQualified $message, array $sources, array $targets): void
    {
        if ($sources === [] || $targets === []) {
            throw new InvalidAdvancement('rounds_missing');
        }

        if (array_intersect_key($sources, $targets) !== []) {
            throw new InvalidAdvancement('round_both_source_and_target');
        }

        $categories = array_unique(array_map(
            static fn (CompetitionRound $round): string => $round->category->value,
            [...array_values($sources), ...array_values($targets)],
        ));

        if (count($categories) !== 1) {
            throw new InvalidAdvancement('category_mismatch');
        }

        if ($message->bestOfEachCountry !== null && ($message->bestOfEachCountry < 1 || $message->bestOfEachCountry > self::MAX_PER_COUNTRY)) {
            throw new InvalidAdvancement('invalid_country_rule');
        }

        if ($message->distribution === AdvanceDistribution::Single && count($targets) !== 1) {
            throw new InvalidAdvancement('single_needs_one_target');
        }

        if ($message->distribution === AdvanceDistribution::BySource) {
            $map = self::targetMap($message);

            foreach (array_keys($sources) as $sourceId) {
                if (!isset($map[$sourceId], $targets[$map[$sourceId]])) {
                    throw new InvalidAdvancement('by_source_map_incomplete');
                }
            }
        }
    }

    /**
     * @return array<string, string> lower-case source round id => lower-case target round id
     */
    private static function targetMap(AdvanceQualified $message): array
    {
        $map = [];
        foreach ($message->targetBySource as $sourceId => $targetId) {
            $map[strtolower($sourceId)] = strtolower($targetId);
        }

        ksort($map);

        return $map;
    }

    /**
     * Who an entry is: the person, a team's exact set of people, or - a team without people - its name.
     */
    private static function identity(RoundResultEntry $entry): string
    {
        $participantIds = $entry->participantIds();

        if ($participantIds === []) {
            return 'name:' . mb_strtolower((string) $entry->name);
        }

        sort($participantIds);

        return 'people:' . implode(',', $participantIds);
    }

    /**
     * 0-based position in the seed order → 0-based target: 0, 1, 1, 0, 0, 1, 1, 0, ... for two targets.
     */
    private static function serpentine(int $index, int $targets): int
    {
        $position = $index % $targets;

        return intdiv($index, $targets) % 2 === 0 ? $position : $targets - 1 - $position;
    }

    /**
     * @param array<string, CompetitionParticipant> $participants the people of the whole plan, loaded at once
     */
    private function advance(RoundResultEntry $entry, CompetitionRound $target, array $participants): string
    {
        if ($target->category === RoundCategory::Solo) {
            assert($entry->participantId !== null);
            $participantRound = new CompetitionParticipantRound(
                Uuid::uuid7(),
                $participants[$entry->participantId] ?? $this->participantRepository->get($entry->participantId),
                $target,
            );
            $this->participantRoundRepository->save($participantRound);

            return $participantRound->entryRef()->toString();
        }

        $team = new CompetitionTeam(Uuid::uuid7(), $target, $entry->name);
        $this->teamRepository->save($team);

        foreach ($entry->participantIds() as $participantId) {
            $this->participantRoundRepository->save(new CompetitionParticipantRound(
                Uuid::uuid7(),
                $participants[$participantId] ?? $this->participantRepository->get($participantId),
                $target,
                $team,
            ));
        }

        return $team->entryRef()->toString();
    }

    private function roundEntry(string $ref): null|CompetitionParticipantRound|CompetitionTeam
    {
        $entryRef = RoundEntryRef::tryFromString($ref);

        if ($entryRef === null) {
            return null;
        }

        return $entryRef->isTeam()
            ? $this->teamRepository->find($entryRef->id)
            : $this->participantRoundRepository->find($entryRef->id);
    }

    /**
     * "Best of each country" over all the source rounds, by the advancement seed: the best `perCountry` ranked entries
     * of every country - a pair/team counts for each of its members' countries. Entries marked qualified count too
     * (a country whose best is in already gets nobody extra).
     *
     * @param array<string, list<RoundResultEntry>> $entriesBySource
     * @param array<string, null|int> $piecesCountBySource
     * @return array{0: array<string, string>, 1: list<array{sourceRoundId: string, entry: RoundResultEntry}>}
     *         [ref of an entry not marked yet that the rule takes => its source round id, ranked entries without a
     *         country that are not marked - left to the organiser]
     */
    private static function bestOfEachCountry(array $entriesBySource, array $piecesCountBySource, int $perCountry): array
    {
        $taken = [];
        $byCountryRule = [];
        $withoutCountry = [];

        $ranked = AdvancementSeeding::seed($entriesBySource, $piecesCountBySource, static fn (RoundResultEntry $entry): bool => $entry->rank !== null);

        foreach ($ranked as $seededEntry) {
            $entry = $seededEntry->entry;
            $countries = array_values(array_unique(array_filter($entry->countries, static fn (string $country): bool => $country !== '')));

            if ($countries === []) {
                if ($entry->isQualified() === false) {
                    $withoutCountry[] = ['sourceRoundId' => $seededEntry->sourceRoundId, 'entry' => $entry];
                }

                continue;
            }

            $selected = false;
            foreach ($countries as $country) {
                $count = $taken[$country] ?? 0;

                if ($count < $perCountry) {
                    $taken[$country] = $count + 1;
                    $selected = true;
                }
            }

            if ($selected && $entry->isQualified() === false) {
                $byCountryRule[$entry->ref->toString()] = $seededEntry->sourceRoundId;
            }
        }

        return [$byCountryRule, $withoutCountry];
    }

    /**
     * @param list<AdvancementAssignment> $assignments
     * @param list<array{seed: int, sourceRoundId: string, entry: RoundResultEntry, reason: string, byCountryRule: bool}> $skipped
     * @param array<string, list<RoundResultEntry>> $targetEntries
     * @param array<string, string> $byCountryRule
     */
    private static function hash(AdvanceQualified $message, array $assignments, array $skipped, array $targetEntries, array $byCountryRule): string
    {
        $markedByRule = array_keys($byCountryRule);
        sort($markedByRule);

        $targetRefs = [];
        foreach ($targetEntries as $roundId => $entries) {
            $refs = array_map(static fn (RoundResultEntry $entry): string => $entry->ref->toString() . '=' . self::identity($entry), $entries);
            sort($refs);
            $targetRefs[$roundId] = $refs;
        }

        return hash('sha256', json_encode([
            'distribution' => $message->distribution->value,
            'sources' => array_map(strtolower(...), $message->sourceRoundIds),
            'targets' => array_map(strtolower(...), $message->targetRoundIds),
            'map' => $message->distribution === AdvanceDistribution::BySource ? self::targetMap($message) : [],
            'assignments' => array_map(static fn (AdvancementAssignment $assignment): array => [
                $assignment->entry->ref->toString(),
                self::identity($assignment->entry),
                $assignment->entry->name,
                $assignment->targetRoundId,
            ], $assignments),
            'skipped' => array_map(static fn (array $skip): array => [$skip['entry']->ref->toString(), $skip['reason']], $skipped),
            'targetEntries' => $targetRefs,
            'bestOfEachCountry' => $message->bestOfEachCountry,
            'markedByCountryRule' => $markedByRule,
        ], JSON_THROW_ON_ERROR));
    }
}
