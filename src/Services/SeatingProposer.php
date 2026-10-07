<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\MessageHandler\RecordRoundResultsHandler;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Query\GetSeatingSeedTimes;
use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Results\RoundResultsOverview;
use SpeedPuzzling\Web\Results\SeatingProposal;
use SpeedPuzzling\Web\Results\SeatingProposalRow;
use SpeedPuzzling\Web\Value\SeatingSource;
use SpeedPuzzling\Web\Value\TeamComposition;

/**
 * Table numbers for every entrant of a round, proposed before anything is written - "auto-assign" of the seating page
 * (docs/features/competitions-management/seating.md). The organiser applies it as one AssignTableNumbers write.
 *
 * The entrants with data come first, ordered by their seed - the fastest at the first table (or the slowest when
 * asked) - then the entrants without data, by name. Sources:
 * - earlier rounds: each entrant's latest ranked result in an earlier round of the event of the same kind (the same
 *   person; a pair/team = exactly the same people), ordered by AdvancementSeeding - the order advancing uses
 * - MySpeedPuzzling times: GetSeatingSeedTimes for the round's piece count; a pair/team by the time of the puzzling
 *   team of exactly these people, else the mean of its members' times
 * - random: a seeded draw; by name.
 * Nothing but the order (and how many had data) reaches the organiser - never anybody's time.
 */
readonly final class SeatingProposer
{
    public const int MAX_TABLE_NUMBER = RecordRoundResultsHandler::MAX_TABLE_NUMBER;

    public function __construct(
        private GetRoundResultEntries $getRoundResultEntries,
        private GetRoundResultsOverview $getRoundResultsOverview,
        private GetSeatingSeedTimes $getSeatingSeedTimes,
    ) {
    }

    /**
     * @param null|SeatingSource $source null = the best available (SeatingProposal::$defaultSource)
     * @throws CompetitionRoundNotFound
     */
    public function propose(
        string $competitionId,
        string $roundId,
        null|SeatingSource $source,
        bool $slowestFirst,
        int $randomSeed,
        int $firstTable,
        string $locale,
    ): SeatingProposal {
        $rounds = $this->getRoundResultsOverview->forCompetition($competitionId);
        $target = null;

        foreach ($rounds as $round) {
            if ($round->roundId === strtolower($roundId)) {
                $target = $round;
            }
        }

        if ($target === null) {
            throw new CompetitionRoundNotFound();
        }

        $entries = $this->getRoundResultEntries->forRound($target->roundId);

        $entriesByEarlierRound = [];
        foreach (self::earlierRounds($rounds, $target) as $earlierRound) {
            $entriesByEarlierRound[$earlierRound->roundId] = $this->getRoundResultEntries->forRound($earlierRound->roundId);
        }
        $earlierSeeds = self::earlierRoundSeeds($rounds, $entries, $entriesByEarlierRound);

        $roundPiecesCount = $this->getSeatingSeedTimes->roundPiecesCount($target->roundId);
        $piecesCount = $roundPiecesCount ?? GetSeatingSeedTimes::DEFAULT_PIECES_COUNT;
        $mspSeconds = $this->mspSeconds($entries, $piecesCount);

        $defaultSource = self::defaultSource(count($entries), count($earlierSeeds), count($mspSeconds));
        $source ??= $defaultSource;
        $compareNames = self::nameComparator($locale);

        $ordered = match ($source) {
            SeatingSource::EarlierRounds => self::orderByKey($entries, array_map(static fn (array $seed): int => $seed['seed'], $earlierSeeds), $slowestFirst, $compareNames),
            SeatingSource::MspTimes => self::orderByKey($entries, $mspSeconds, $slowestFirst, $compareNames),
            SeatingSource::Random => self::orderRandomly($entries, $randomSeed),
            SeatingSource::Name => self::orderByKey($entries, [], false, $compareNames),
        };

        $rows = [];
        foreach ($ordered as $index => $entry) {
            $ref = $entry->ref->toString();
            $basis = $source === SeatingSource::EarlierRounds ? ($earlierSeeds[$ref] ?? null) : null;

            $rows[] = new SeatingProposalRow(
                entryRef: $ref,
                displayName: $entry->displayName(),
                tableNumber: $firstTable + $index,
                currentTableNumber: $entry->tableNumber,
                hasData: match ($source) {
                    SeatingSource::EarlierRounds => isset($earlierSeeds[$ref]),
                    SeatingSource::MspTimes => isset($mspSeconds[$ref]),
                    SeatingSource::Random, SeatingSource::Name => true,
                },
                basisRoundId: $basis['roundId'] ?? null,
                basisRoundName: $basis['roundName'] ?? null,
                basisRank: $basis['rank'] ?? null,
            );
        }

        return new SeatingProposal(
            roundId: $target->roundId,
            source: $source,
            defaultSource: $defaultSource,
            withDataBySource: [
                SeatingSource::EarlierRounds->value => count($earlierSeeds),
                SeatingSource::MspTimes->value => count($mspSeconds),
                SeatingSource::Random->value => count($entries),
                SeatingSource::Name->value => count($entries),
            ],
            slowestFirst: $slowestFirst,
            randomSeed: $source === SeatingSource::Random ? $randomSeed : null,
            piecesCount: $piecesCount,
            piecesCountAssumed: $roundPiecesCount === null,
            firstTable: $firstTable,
            rows: $rows,
        );
    }

    /**
     * Earlier rounds when they place at least half of the entrants (the entrants came from qualification) or more of
     * them than MySpeedPuzzling times; else MySpeedPuzzling times; by name when neither has anything.
     */
    public static function defaultSource(int $entries, int $earlierWithData, int $mspWithData): SeatingSource
    {
        if ($earlierWithData > 0 && ($earlierWithData * 2 >= $entries || $earlierWithData >= $mspWithData)) {
            return SeatingSource::EarlierRounds;
        }

        if ($mspWithData > 0) {
            return SeatingSource::MspTimes;
        }

        return SeatingSource::Name;
    }

    /**
     * The rounds an entrant may come from: the same kind (people / pairs / teams), starting no later than the round
     * itself (organisers often keep one placeholder start for rounds of a day), the round itself left out.
     *
     * @param list<RoundResultsOverview> $rounds
     * @return list<RoundResultsOverview> in the event's order
     */
    public static function earlierRounds(array $rounds, RoundResultsOverview $target): array
    {
        return array_values(array_filter(
            $rounds,
            static fn (RoundResultsOverview $round): bool => $round->roundId !== $target->roundId
                && $round->category === $target->category
                && $round->startsAt <= $target->startsAt,
        ));
    }

    /**
     * Each entrant's advancement seed from earlier rounds: its latest earlier round in which the same person - or
     * exactly the same people for a pair/team - has a ranked result (a time or pieces placed), then AdvancementSeeding
     * over those results, ranked within their own rounds. Entrants without such a result are left out.
     *
     * @param list<RoundResultsOverview> $rounds every round of the event, in its order
     * @param list<RoundResultEntry> $entries the round's entrants
     * @param array<string, list<RoundResultEntry>> $entriesByEarlierRound round id => all its entries (ranked)
     * @return array<string, array{seed: int, roundId: string, roundName: string, rank: int}> entrant ref => seed (1 = best)
     */
    public static function earlierRoundSeeds(array $rounds, array $entries, array $entriesByEarlierRound): array
    {
        $roundsById = [];
        foreach ($rounds as $round) {
            $roundsById[$round->roundId] = $round;
        }

        // Latest first (the event's order reversed): an entrant of a final is seeded by the semifinal, not by the group
        // they came from before it
        $latestFirst = array_reverse(array_values(array_filter($rounds, static fn (RoundResultsOverview $round): bool => isset($entriesByEarlierRound[$round->roundId]))));

        /** @var array<string, array<string, RoundResultEntry>> $rankedByPeople round id => people key => entry */
        $rankedByPeople = [];
        foreach ($entriesByEarlierRound as $roundId => $roundEntries) {
            foreach ($roundEntries as $roundEntry) {
                $key = self::peopleKey($roundEntry);

                if ($roundEntry->rank !== null && $key !== null) {
                    $rankedByPeople[$roundId][$key] = $roundEntry;
                }
            }
        }

        /** @var array<string, list<string>> $targetRefsBySource source entry ref => entrant refs */
        $targetRefsBySource = [];
        /** @var array<string, true> $sourceRounds */
        $sourceRounds = [];

        foreach ($entries as $entry) {
            $key = self::peopleKey($entry);

            if ($key === null) {
                continue;
            }

            foreach ($latestFirst as $round) {
                $source = $rankedByPeople[$round->roundId][$key] ?? null;

                if ($source !== null) {
                    $targetRefsBySource[$source->ref->toString()][] = $entry->ref->toString();
                    $sourceRounds[$round->roundId] = true;

                    break;
                }
            }
        }

        if ($targetRefsBySource === []) {
            return [];
        }

        // Every entry of each source round goes in, so its winner is the round's winner; only the sources are seeded
        $entriesBySourceRound = [];
        $piecesCountBySourceRound = [];
        foreach ($rounds as $round) {
            if (isset($sourceRounds[$round->roundId])) {
                $entriesBySourceRound[$round->roundId] = $entriesByEarlierRound[$round->roundId];
                $piecesCountBySourceRound[$round->roundId] = $round->piecesCount;
            }
        }

        $seeded = AdvancementSeeding::seed(
            $entriesBySourceRound,
            $piecesCountBySourceRound,
            static fn (RoundResultEntry $candidate): bool => isset($targetRefsBySource[$candidate->ref->toString()]),
        );

        $seeds = [];
        foreach ($seeded as $seededEntry) {
            foreach ($targetRefsBySource[$seededEntry->entry->ref->toString()] ?? [] as $targetRef) {
                $seeds[$targetRef] = [
                    'seed' => $seededEntry->seed,
                    'roundId' => $seededEntry->sourceRoundId,
                    'roundName' => $roundsById[$seededEntry->sourceRoundId]->name ?? '',
                    'rank' => $seededEntry->entry->rank ?? 0,
                ];
            }
        }

        return $seeds;
    }

    /**
     * The entrants with a key first - smallest first, or largest first - then the rest; ties and the rest by name.
     *
     * @param list<RoundResultEntry> $entries
     * @param array<string, int|float> $keyByRef entrant ref => sort key (smaller = faster)
     * @param callable(RoundResultEntry, RoundResultEntry): int $compareNames
     * @return list<RoundResultEntry>
     */
    public static function orderByKey(array $entries, array $keyByRef, bool $largestFirst, callable $compareNames): array
    {
        usort($entries, static function (RoundResultEntry $a, RoundResultEntry $b) use ($keyByRef, $largestFirst, $compareNames): int {
            $aKey = $keyByRef[$a->ref->toString()] ?? null;
            $bKey = $keyByRef[$b->ref->toString()] ?? null;

            if ($aKey === null || $bKey === null) {
                return ($aKey === null) <=> ($bKey === null) ?: $compareNames($a, $b);
            }

            return ($largestFirst ? $bKey <=> $aKey : $aKey <=> $bKey) ?: $compareNames($a, $b);
        });

        return $entries;
    }

    /**
     * A draw the organiser can repeat: the same number and the same entrants give the same order.
     *
     * @param list<RoundResultEntry> $entries
     * @return list<RoundResultEntry>
     */
    public static function orderRandomly(array $entries, int $seed): array
    {
        $keys = [];
        foreach ($entries as $entry) {
            $keys[$entry->ref->toString()] = hash('sha256', $seed . ':' . $entry->ref->toString());
        }

        usort($entries, static fn (RoundResultEntry $a, RoundResultEntry $b): int => strcmp($keys[$a->ref->toString()], $keys[$b->ref->toString()]));

        return $entries;
    }

    /**
     * Each entrant's expected time in seconds: a person by their own times; a pair/team by the times of the puzzling
     * team of exactly these people when all of them are players visible to the organiser and it has a time of this
     * piece count, else by the mean of its members' times (members without data left out).
     *
     * @param list<RoundResultEntry> $entries
     * @param array<string, int> $playerSeconds player id => seconds (GetSeatingSeedTimes::forPlayers)
     * @param array<string, int> $teamSeconds composition key => seconds (GetSeatingSeedTimes::forTeams)
     * @param array<string, true> $visiblePlayers player ids the organiser may see (not private to them)
     * @return array<string, int> entrant ref => seconds, entrants without data left out
     */
    public static function entrySeconds(array $entries, array $playerSeconds, array $teamSeconds, array $visiblePlayers): array
    {
        $seconds = [];

        foreach ($entries as $entry) {
            $ref = $entry->ref->toString();

            if ($entry->kind === RoundResultEntry::KIND_PERSON) {
                $playerId = $entry->playerId !== null ? strtolower($entry->playerId) : null;

                if ($playerId !== null && isset($playerSeconds[$playerId])) {
                    $seconds[$ref] = $playerSeconds[$playerId];
                }

                continue;
            }

            $compositionKey = self::compositionKey($entry, $visiblePlayers);

            if ($compositionKey !== null && isset($teamSeconds[$compositionKey])) {
                $seconds[$ref] = $teamSeconds[$compositionKey];

                continue;
            }

            $memberSeconds = [];
            foreach ($entry->members as $member) {
                $playerId = $member->playerId !== null ? strtolower($member->playerId) : null;

                if ($playerId !== null && isset($playerSeconds[$playerId])) {
                    $memberSeconds[] = $playerSeconds[$playerId];
                }
            }

            if ($memberSeconds !== []) {
                $seconds[$ref] = (int) round(array_sum($memberSeconds) / count($memberSeconds));
            }
        }

        return $seconds;
    }

    /**
     * The puzzling_team identity of a pair/team whose every member is a player visible to the organiser - else null
     * (a guest's name could match anybody's guest, a private member's times stay theirs).
     *
     * @param array<string, true> $visiblePlayers
     */
    public static function compositionKey(RoundResultEntry $entry, array $visiblePlayers): null|string
    {
        if ($entry->kind !== RoundResultEntry::KIND_TEAM || count($entry->members) < 2) {
            return null;
        }

        $memberKeys = [];
        foreach ($entry->members as $member) {
            if ($member->playerId === null || !isset($visiblePlayers[strtolower($member->playerId)])) {
                return null;
            }

            $memberKeys[strtolower($member->playerId)] = strtolower($member->playerId);
        }

        // The same account linked to two members of the team is not a pair of people
        if (count($memberKeys) !== count($entry->members)) {
            return null;
        }

        return TeamComposition::keyFromMemberKeys(array_values($memberKeys));
    }

    /**
     * @return callable(RoundResultEntry, RoundResultEntry): int
     */
    public static function nameComparator(string $locale): callable
    {
        $collator = \Collator::create($locale);

        return static function (RoundResultEntry $a, RoundResultEntry $b) use ($collator): int {
            $compared = $collator?->compare($a->displayName(), $b->displayName());

            if (!is_int($compared)) {
                $compared = strcasecmp($a->displayName(), $b->displayName());
            }

            return $compared ?: strcmp($a->ref->id, $b->ref->id);
        };
    }

    /**
     * @param list<RoundResultEntry> $entries
     * @return array<string, int>
     */
    private function mspSeconds(array $entries, int $piecesCount): array
    {
        $playerIds = [];
        foreach ($entries as $entry) {
            if ($entry->playerId !== null) {
                $playerIds[] = $entry->playerId;
            }

            foreach ($entry->members as $member) {
                if ($member->playerId !== null) {
                    $playerIds[] = $member->playerId;
                }
            }
        }

        $players = $this->getSeatingSeedTimes->forPlayers($playerIds, $piecesCount);
        $visiblePlayers = array_fill_keys(array_keys($players), true);
        $playerSeconds = array_filter($players, static fn (null|int $seconds): bool => $seconds !== null);

        $compositionKeys = [];
        foreach ($entries as $entry) {
            $key = self::compositionKey($entry, $visiblePlayers);

            if ($key !== null) {
                $compositionKeys[] = $key;
            }
        }

        return self::entrySeconds($entries, $playerSeconds, $this->getSeatingSeedTimes->forTeams($compositionKeys, $piecesCount), $visiblePlayers);
    }

    /**
     * Who an entry is, independent of the round: the person, or the exact set of people of a pair/team.
     */
    private static function peopleKey(RoundResultEntry $entry): null|string
    {
        $participantIds = array_map(strtolower(...), $entry->participantIds());

        if ($participantIds === []) {
            return null;
        }

        sort($participantIds);

        return $entry->kind . ':' . implode(',', array_unique($participantIds));
    }
}
