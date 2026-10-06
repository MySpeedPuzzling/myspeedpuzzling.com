<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Results\ParticipantImportResult;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Rules (documented in docs/features/competitions-management/participants.md §Excel Import):
 * - a row is matched to an existing participant by participant_id, msp_player_id, external_id, then name
 *   (+ country); a name shared by several participants of the event is never guessed - the row is reported
 * - rows of the same person add up: every round of every row is assigned, the person counts once
 * - round assignments are only added: nobody leaves a round or changes team through an import
 * - an export imported back unchanged changes nothing
 */
readonly final class CompetitionParticipantImporter
{
    /**
     * Every column the importer reads. `round_names` (a list) is what the template and the export write;
     * `round_name` (one round, never split) is kept for files made before.
     */
    public const array KNOWN_COLUMNS = ['name', 'country', 'external_id', 'msp_player_id', 'status', 'round_names', 'round_name', 'team_name', 'participant_id'];

    /** A column "team_name: <round>" holds the team in that one round (the export writes one per pair/team round). */
    public const string TEAM_COLUMN_PREFIX = 'team_name:';

    private const string MESSAGE_PREFIX = 'competition.participants.import.';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompetitionRepository $competitionRepository,
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function import(string $competitionId, string $filePath): ParticipantImportResult
    {
        $competition = $this->competitionRepository->get($competitionId);

        try {
            $rows = IOFactory::load($filePath)->getActiveSheet()->toArray();
        } catch (SpreadsheetException | \ValueError) {
            return new ParticipantImportResult(errors: [self::message('unreadable_file')]);
        }

        if ($rows === []) {
            return new ParticipantImportResult(errors: [self::message('empty_file')]);
        }

        /** @var array<null|scalar> $headerRow */
        $headerRow = array_shift($rows);
        /** @var array<int, string> $rawHeaders */
        $rawHeaders = array_map(static fn (mixed $header): string => trim((string) $header), $headerRow);
        $headers = array_map(strtolower(...), $rawHeaders);

        $nameIdx = self::findColumnIndex($headers, 'name');
        $countryIdx = self::findColumnIndex($headers, 'country');
        $externalIdIdx = self::findColumnIndex($headers, 'external_id');
        $playerIdIdx = self::findColumnIndex($headers, 'msp_player_id');
        $statusIdx = self::findColumnIndex($headers, 'status');
        $roundNameIdx = self::findColumnIndex($headers, 'round_name');
        $roundNamesIdx = self::findColumnIndex($headers, 'round_names');
        $teamNameIdx = self::findColumnIndex($headers, 'team_name');
        $participantIdIdx = self::findColumnIndex($headers, 'participant_id');

        if ($nameIdx === null) {
            return new ParticipantImportResult(errors: [self::message('name_column_missing')]);
        }

        $warnings = [];
        $errors = [];

        [$rounds, $roundCollisions] = $this->loadRounds($competitionId);
        foreach ($roundCollisions as $collision) {
            $warnings[] = self::message('round_case_collision', [
                '%rounds%' => self::quotedList($collision),
                '%used%' => $collision[0],
            ]);
        }

        /** @var array<string, CompetitionRound> $roundsById */
        $roundsById = [];
        foreach ($rounds as $round) {
            $roundsById[$round->id->toString()] = $round;
        }

        // "team_name: <round>" columns, and columns nobody reads
        /** @var array<string, int> $teamColumns roundId => column index */
        $teamColumns = [];
        $unknownColumns = [];
        foreach ($headers as $idx => $header) {
            if (str_starts_with($header, self::TEAM_COLUMN_PREFIX)) {
                $roundLabel = trim(substr($rawHeaders[$idx], strlen(self::TEAM_COLUMN_PREFIX)));
                $round = $rounds[self::roundKey($roundLabel)] ?? null;

                if ($round === null) {
                    $warnings[] = self::message('unknown_team_round_column', [
                        '%column%' => $rawHeaders[$idx],
                        '%round%' => $roundLabel,
                    ]);
                } elseif ($round->category !== RoundCategory::Solo) {
                    $teamColumns[$round->id->toString()] = $idx;
                }

                continue;
            }

            if ($header !== '' && !in_array($header, self::KNOWN_COLUMNS, true)) {
                $unknownColumns[] = $rawHeaders[$idx];
            }
        }

        if ($unknownColumns !== []) {
            $warnings[] = self::message('unknown_columns', [
                '%columns%' => self::quotedList(array_values(array_unique($unknownColumns))),
                '%known%' => implode(', ', [...self::KNOWN_COLUMNS, self::TEAM_COLUMN_PREFIX . ' <round>']),
            ]);
        }

        $existing = $this->loadExistingParticipants($competitionId);

        /** @var array<string, CompetitionParticipant> $touched participantId => participant a row matched or created */
        $touched = [];
        /** @var array<string, null|array<string, mixed>> $before participantId => its state before the import, null = created by it */
        $before = [];

        /**
         * Rows of the same participant add up: every round from every row is assigned.
         *
         * @var array<string, array<string, array{team: null|string, canFill: bool, row: int}>> $roundAssignments participantId => [roundId => assignment]
         */
        $roundAssignments = [];

        /** @var array<string, list<int>> $unknownRoundNames round name as written in the file => row numbers */
        $unknownRoundNames = [];

        foreach ($rows as $rowIndex => $row) {
            /** @var array<int, null|scalar> $row */
            $rowNum = $rowIndex + 2;

            $cell = static fn (null|int $idx): string => $idx !== null ? trim((string) ($row[$idx] ?? '')) : '';

            $name = $cell($nameIdx);
            if ($name === '') {
                if (implode('', array_map(static fn (mixed $value): string => trim((string) $value), $row)) !== '') {
                    $errors[] = self::message('missing_name', ['%row%' => $rowNum]);
                }

                continue;
            }

            $country = $cell($countryIdx);
            $externalId = $cell($externalIdIdx);
            $playerId = $cell($playerIdIdx);
            $status = strtolower($cell($statusIdx));
            $participantIdCell = $cell($participantIdIdx);

            $countryCode = null;
            if ($country !== '') {
                $countryCode = CountryCode::fromCode($country);
                if ($countryCode === null) {
                    $warnings[] = self::message('invalid_country', ['%row%' => $rowNum, '%country%' => $country]);
                }
            }

            $validPlayerId = null;
            if ($playerId !== '') {
                if (!Uuid::isValid($playerId)) {
                    $errors[] = self::message('invalid_player_id', ['%row%' => $rowNum, '%id%' => $playerId]);
                } elseif (!$this->playerExists($playerId)) {
                    $errors[] = self::message('unknown_player_id', ['%row%' => $rowNum, '%id%' => $playerId]);
                } else {
                    $validPlayerId = $playerId;
                }
            }

            // Priority 0: the participant's own id (the export writes it)
            $matchedId = null;
            if ($participantIdCell !== '') {
                $normalisedId = strtolower($participantIdCell);

                if (isset($existing[$normalisedId])) {
                    $matchedId = $normalisedId;
                } else {
                    $warnings[] = self::message('foreign_participant_id', ['%row%' => $rowNum, '%id%' => $participantIdCell]);
                }
            }

            if ($matchedId === null) {
                $match = self::findMatch($existing, $validPlayerId, $externalId !== '' ? $externalId : null, $name, $countryCode?->name);

                if (is_int($match)) {
                    $warnings[] = self::message('ambiguous_name', ['%row%' => $rowNum, '%name%' => $name, '%count%' => $match]);

                    continue;
                }

                $matchedId = $match;
            }

            if ($matchedId !== null) {
                $participant = $this->entityManager->find(CompetitionParticipant::class, $matchedId);
                assert($participant instanceof CompetitionParticipant);

                if (!array_key_exists($matchedId, $before)) {
                    $before[$matchedId] = self::state($participant);
                }

                $participant->updateName($name);
                if ($participant->source === ParticipantSource::SelfJoined) {
                    $participant->markAsImported();
                }
                if ($countryCode !== null) {
                    $participant->updateCountry($countryCode->name);
                }
                if ($externalId !== '') {
                    $participant->updateExternalId($externalId);
                }
                if ($validPlayerId !== null && ($participant->player === null || $participant->player->id->toString() !== $validPlayerId)) {
                    $player = $this->entityManager->find(Player::class, $validPlayerId);
                    if ($player !== null) {
                        $participant->connect($player, $this->clock->now());
                    }
                }

                if ($participant->isDeleted()) {
                    $participant->restore();
                }
            } else {
                $participant = new CompetitionParticipant(
                    id: Uuid::uuid7(),
                    name: $name,
                    country: $countryCode?->name,
                    competition: $competition,
                    source: ParticipantSource::Imported,
                );

                if ($externalId !== '') {
                    $participant->updateExternalId($externalId);
                }

                if ($validPlayerId !== null) {
                    $player = $this->entityManager->find(Player::class, $validPlayerId);
                    if ($player !== null) {
                        $participant->connect($player, $this->clock->now());
                    }
                }

                $this->entityManager->persist($participant);
                $before[$participant->id->toString()] = null;
            }

            $participantId = $participant->id->toString();
            $touched[$participantId] = $participant;

            if ($status === 'deleted') {
                $participant->softDelete($this->clock->now());
            }

            // Later rows match what this row made of the participant (a rename, a new external id, ...)
            $existing[$participantId] = [
                'id' => $participantId,
                'name' => $participant->name,
                'country' => $participant->country,
                'external_id' => $participant->externalId,
                'player_id' => $participant->player?->id->toString(),
                'deleted' => $participant->isDeleted(),
            ];

            // A participant removed by the file gets no rounds
            if ($status === 'deleted') {
                continue;
            }

            // Rounds and teams, applied after the participants are flushed
            $teamName = $this->cleanTeamName($cell($teamNameIdx), $rowNum, $warnings);

            /** @var array<string, null|string> $rowTeams roundId => the row's "team_name: <round>" cell */
            $rowTeams = [];
            foreach ($teamColumns as $roundId => $columnIdx) {
                $rowTeams[$roundId] = $this->cleanTeamName($cell($columnIdx), $rowNum, $warnings);
            }

            $requestedRoundNames = $roundNamesIdx !== null ? self::splitRoundNames($cell($roundNamesIdx), $rounds) : [];
            if ($roundNameIdx !== null && $cell($roundNameIdx) !== '') {
                $requestedRoundNames[] = $cell($roundNameIdx);
            }

            /** @var array<string, CompetitionRound> $rowRounds */
            $rowRounds = [];
            foreach ($requestedRoundNames as $requestedRoundName) {
                $round = $rounds[self::roundKey($requestedRoundName)] ?? null;

                if ($round === null) {
                    $unknownRoundNames[$requestedRoundName][] = $rowNum;

                    continue;
                }

                $rowRounds[$round->id->toString()] = $round;
            }

            foreach ($rowTeams as $roundId => $roundTeam) {
                if ($roundTeam !== null && !isset($rowRounds[$roundId])) {
                    $warnings[] = self::message('team_round_not_listed', [
                        '%row%' => $rowNum,
                        '%team%' => $roundTeam,
                        '%round%' => $roundsById[$roundId]->name,
                    ]);
                }
            }

            // A filled "team_name: <round>" cell wins; otherwise team_name applies. A team_name covering several
            // pair/team rounds (an export made before the per-round columns) never joins a team somebody already
            // in the round has not got - it may belong to another round.
            $genericTeamRounds = array_filter(
                $rowRounds,
                static fn (CompetitionRound $round): bool => $round->category !== RoundCategory::Solo && ($rowTeams[$round->id->toString()] ?? null) === null,
            );
            $genericTeamIsAmbiguous = $teamName !== null && count($genericTeamRounds) > 1;

            foreach ($rowRounds as $roundId => $round) {
                if ($round->category === RoundCategory::Solo) {
                    $assignment = ['team' => null, 'canFill' => true, 'row' => $rowNum];
                } elseif (($rowTeams[$roundId] ?? null) !== null) {
                    $assignment = ['team' => $rowTeams[$roundId], 'canFill' => true, 'row' => $rowNum];
                } else {
                    $assignment = ['team' => $teamName, 'canFill' => !$genericTeamIsAmbiguous, 'row' => $rowNum];
                }

                $current = $roundAssignments[$participantId][$roundId] ?? null;

                if (
                    $current === null
                    || ($current['team'] === null && $assignment['team'] !== null)
                    // The same team, named for this one round by a later row, may fill what an ambiguous row could not
                    || ($assignment['canFill'] && !$current['canFill'] && $assignment['team'] !== null && self::sameTeamName($assignment['team'], $current['team']))
                ) {
                    $roundAssignments[$participantId][$roundId] = $assignment;
                } elseif ($assignment['team'] !== null && !self::sameTeamName($assignment['team'], $current['team'])) {
                    $warnings[] = self::message('team_conflict_in_file', [
                        '%row%' => $rowNum,
                        '%name%' => $name,
                        '%team%' => (string) $current['team'],
                        '%round%' => $round->name,
                        '%ignored%' => $assignment['team'],
                    ]);
                }
            }
        }

        foreach ($unknownRoundNames as $unknownRoundName => $rowNumbers) {
            $warnings[] = self::message('unknown_round', [
                '%round%' => (string) $unknownRoundName,
                '%rows%' => self::listRowNumbers($rowNumbers),
                '%count%' => count($rowNumbers),
                '%rounds%' => $rounds === []
                    ? self::message('no_rounds_yet')
                    : self::quotedList(array_map(static fn (CompetitionRound $round): string => $round->name, array_values($rounds))),
            ]);
        }

        $this->entityManager->flush();

        // Post-flush: assign participants to rounds and teams
        $roundsChangedFor = [];
        if ($roundAssignments !== []) {
            [$assignmentWarnings, $roundsChangedFor] = $this->processRoundAndTeamAssignments($competitionId, $roundAssignments, $roundsById);
            $warnings = [...$warnings, ...$assignmentWarnings];
            $this->entityManager->flush();
        }

        // Counted per person, and "updated" only when something about them changed
        $added = 0;
        $updated = 0;
        $unchanged = 0;
        $softDeleted = 0;
        foreach ($touched as $participantId => $participant) {
            $previous = $before[$participantId] ?? null;

            if ($participant->isDeleted() && ($previous === null || $previous['deleted'] === false)) {
                $softDeleted++;
            } elseif ($previous === null) {
                $added++;
            } elseif ($previous !== self::state($participant) || isset($roundsChangedFor[$participantId])) {
                $updated++;
            } else {
                $unchanged++;
            }
        }

        return new ParticipantImportResult(
            added: $added,
            updated: $updated,
            softDeleted: $softDeleted,
            warnings: $warnings,
            errors: $errors,
            unchanged: $unchanged,
        );
    }

    /**
     * What the organiser sees of a participant - `source` (self-joined becoming the organiser's, markAsImported())
     * is bookkeeping, not a change they made.
     *
     * @return array<string, mixed>
     */
    private static function state(CompetitionParticipant $participant): array
    {
        return [
            'name' => $participant->name,
            'country' => $participant->country,
            'external_id' => $participant->externalId,
            'player_id' => $participant->player?->id->toString(),
            'deleted' => $participant->isDeleted(),
        ];
    }

    /**
     * @param list<TranslatableMessage> $warnings
     */
    private function cleanTeamName(string $value, int $rowNum, array &$warnings): null|string
    {
        $teamName = CompetitionTeam::cleanName($value);

        if ($teamName !== null && mb_strlen($teamName) > CompetitionTeam::NAME_MAX_LENGTH) {
            $warnings[] = self::message('team_name_too_long', ['%row%' => $rowNum, '%max%' => CompetitionTeam::NAME_MAX_LENGTH]);

            return null;
        }

        return $teamName;
    }

    /**
     * @return array<string, array{id: string, name: string, country: null|string, external_id: null|string, player_id: null|string, deleted: bool}>
     */
    private function loadExistingParticipants(string $competitionId): array
    {
        $query = <<<SQL
SELECT id, name, country, external_id, player_id, deleted_at IS NOT NULL AS deleted
FROM competition_participant
WHERE competition_id = :competitionId
-- A player's own "I left" record is not the organizer's data: restoring it would sign them up again
AND NOT (source = 'self_joined' AND deleted_at IS NOT NULL)
-- Active rows first, so a player's live row wins over a soft-deleted one with the same match
ORDER BY deleted_at IS NOT NULL, id
SQL;

        $existing = [];
        foreach ($this->database->executeQuery($query, ['competitionId' => $competitionId])->fetchAllAssociative() as $row) {
            /** @var array{id: string, name: string, country: null|string, external_id: null|string, player_id: null|string, deleted: bool} $row */
            $existing[$row['id']] = $row;
        }

        return $existing;
    }

    /**
     * @param array<string, array{id: string, name: string, country: null|string, external_id: null|string, player_id: null|string, deleted: bool}> $existing
     * @param null|string $playerId the row's msp_player_id when it is an existing player - a typo is reported, never a reason to treat the row as another person
     * @return null|string|int the participant id, null for nobody, or how many participants share the name (never guessed)
     */
    private static function findMatch(array $existing, null|string $playerId, null|string $externalId, string $name, null|string $country): null|string|int
    {
        // Priority 1: match by msp_player_id
        if ($playerId !== null) {
            foreach ($existing as $row) {
                if ($row['player_id'] === $playerId) {
                    return $row['id'];
                }
            }
        }

        // Priority 2: match by external_id
        if ($externalId !== null) {
            foreach ($existing as $row) {
                if ($row['external_id'] !== null && $row['external_id'] === $externalId) {
                    return $row['id'];
                }
            }
        }

        // Priority 3: name + country, then the name alone. Somebody with another external id or another linked
        // player is another person of the same name, never this row.
        $sameName = array_filter(
            $existing,
            static fn (array $row): bool => $row['name'] === $name
                && ($externalId === null || $row['external_id'] === null || $row['external_id'] === $externalId)
                && ($playerId === null || $row['player_id'] === null || strtolower($playerId) === $row['player_id']),
        );

        if ($country !== null) {
            $sameNameAndCountry = array_filter($sameName, static fn (array $row): bool => $row['country'] === $country);

            if ($sameNameAndCountry !== []) {
                return self::single($sameNameAndCountry);
            }
        }

        return $sameName === [] ? null : self::single($sameName);
    }

    /**
     * One candidate, or the only active one among them - otherwise how many there are.
     *
     * @param non-empty-array<array{id: string, deleted: bool, ...}> $candidates
     */
    private static function single(array $candidates): string|int
    {
        $active = array_filter($candidates, static fn (array $row): bool => $row['deleted'] === false);
        $pool = $active !== [] ? $active : $candidates;

        return count($pool) === 1 ? reset($pool)['id'] : count($pool);
    }

    /**
     * @param array<string> $headers
     */
    private static function findColumnIndex(array $headers, string $columnName): null|int
    {
        $key = array_search($columnName, $headers, true);

        return $key !== false ? (int) $key : null;
    }

    private function playerExists(string $playerId): bool
    {
        /** @var false|string $result */
        $result = $this->database->executeQuery(
            'SELECT id FROM player WHERE id = :id',
            ['id' => $playerId],
        )->fetchOne();

        return $result !== false;
    }

    /**
     * @return array{array<string, CompetitionRound>, list<non-empty-list<string>>} normalised name (see roundKey()) => round,
     *         and the round names the import cannot tell apart (the first of each is used)
     */
    private function loadRounds(string $competitionId): array
    {
        $query = <<<SQL
SELECT id FROM competition_round WHERE competition_id = :competitionId ORDER BY starts_at, name
SQL;

        $roundIds = $this->database
            ->executeQuery($query, ['competitionId' => $competitionId])
            ->fetchFirstColumn();

        $rounds = [];
        /** @var array<string, non-empty-list<string>> $namesByKey */
        $namesByKey = [];
        foreach ($roundIds as $roundId) {
            $round = $this->entityManager->find(CompetitionRound::class, $roundId);
            if ($round !== null) {
                $key = self::roundKey($round->name);
                $rounds[$key] ??= $round;
                $namesByKey[$key][] = $round->name;
            }
        }

        $collisions = array_values(array_filter($namesByKey, static fn (array $names): bool => count($names) > 1));

        return [$rounds, $collisions];
    }

    /**
     * Import only adds: a participant already in a round stays there with their team. A missing
     * team is filled in; a different team in the file is reported, never switched.
     *
     * @param array<string, array<string, array{team: null|string, canFill: bool, row: int}>> $assignments participantId => [roundId => assignment]
     * @param array<string, CompetitionRound> $roundsById
     * @return array{list<TranslatableMessage>, array<string, true>} warnings, and the participants whose rounds or teams changed
     */
    private function processRoundAndTeamAssignments(string $competitionId, array $assignments, array $roundsById): array
    {
        $existingPr = $this->database->executeQuery(
            'SELECT cpr.id, cpr.participant_id, cp.name AS participant_name, cpr.round_id, ct.name AS team_name, cpr.team_id
             FROM competition_participant_round cpr
             INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
             LEFT JOIN competition_team ct ON ct.id = cpr.team_id
             WHERE cp.competition_id = :competitionId',
            ['competitionId' => $competitionId],
        )->fetchAllAssociative();

        /** @var array<string, array<string, array{id: string, participant_name: string, team_id: null|string, team_name: null|string}>> $existingByParticipant */
        $existingByParticipant = [];
        foreach ($existingPr as $row) {
            /** @var array{id: string, participant_id: string, participant_name: string, round_id: string, team_id: null|string, team_name: null|string} $row */
            $existingByParticipant[$row['participant_id']][$row['round_id']] = $row;
        }

        /** @var array<string, CompetitionTeam> $teamCache roundId:lowercased team name => team */
        $teamCache = [];
        $warnings = [];
        $changed = [];

        foreach ($assignments as $participantId => $participantRounds) {
            foreach ($participantRounds as $roundId => $assignment) {
                $teamName = $assignment['team'];
                $round = $roundsById[$roundId] ?? null;
                if ($round === null) {
                    continue;
                }

                $existing = $existingByParticipant[$participantId][$roundId] ?? null;

                if ($existing !== null && ($teamName === null || $existing['team_id'] !== null)) {
                    if ($teamName !== null && !self::sameTeamName($teamName, $existing['team_name'])) {
                        $warnings[] = self::message('team_kept', [
                            '%name%' => $existing['participant_name'],
                            '%team%' => $existing['team_name'] ?? '',
                            '%round%' => $round->name,
                            '%ignored%' => $teamName,
                        ]);
                    }

                    continue;
                }

                if ($existing !== null && !$assignment['canFill']) {
                    $warnings[] = self::message('team_not_filled', [
                        '%row%' => $assignment['row'],
                        '%team%' => (string) $teamName,
                        '%round%' => $round->name,
                        '%name%' => $existing['participant_name'],
                    ]);

                    continue;
                }

                $team = null;
                if ($teamName !== null) {
                    $cacheKey = $roundId . ':' . mb_strtolower($teamName);
                    $team = $teamCache[$cacheKey] ??= $this->findOrCreateTeam($round, $teamName);
                }

                // Already in the round without a team (other cases continued above): fill the team in
                if ($existing !== null) {
                    $participantRound = $this->entityManager->find(CompetitionParticipantRound::class, $existing['id']);
                    $participantRound?->assignToTeam($team);
                    $changed[$participantId] = true;

                    continue;
                }

                $participant = $this->entityManager->find(CompetitionParticipant::class, $participantId);
                if ($participant === null) {
                    continue;
                }

                $this->entityManager->persist(new CompetitionParticipantRound(
                    id: Uuid::uuid7(),
                    participant: $participant,
                    round: $round,
                    team: $team,
                ));
                $changed[$participantId] = true;
            }
        }

        return [$warnings, $changed];
    }

    /**
     * A `round_names` cell: round names separated by commas or semicolons. A round whose own name
     * contains a comma or semicolon is recognised too - the longest run of pieces that is a round's
     * name wins. Pieces matching no round are returned as written, to be reported.
     *
     * @param array<string, CompetitionRound> $rounds
     * @return list<string>
     */
    private static function splitRoundNames(string $cell, array $rounds): array
    {
        if (trim($cell) === '') {
            return [];
        }

        /** @var list<string> $parts pieces and the separators between them: piece, separator, piece, ... */
        $parts = preg_split('/([,;])/', $cell, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$cell];
        $pieceCount = intdiv(count($parts) + 1, 2);

        $names = [];
        $i = 0;
        while ($i < $pieceCount) {
            $taken = 1;
            $name = trim($parts[$i * 2]);

            for ($j = $pieceCount - 1; $j > $i; $j--) {
                $candidate = trim(implode('', array_slice($parts, $i * 2, ($j - $i) * 2 + 1)));

                if (isset($rounds[self::roundKey($candidate)])) {
                    $taken = $j - $i + 1;
                    $name = $candidate;
                    break;
                }
            }

            if ($name !== '') {
                $names[] = $name;
            }

            $i += $taken;
        }

        return $names;
    }

    /**
     * Round names match ignoring case and repeated whitespace (also around separators) - "team relay" is "Team Relay".
     */
    private static function roundKey(string $name): string
    {
        $name = (string) preg_replace('/\s*([,;])\s*/u', '$1 ', $name);

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    private static function sameTeamName(string $a, null|string $b): bool
    {
        return $b !== null && mb_strtolower($a) === mb_strtolower($b);
    }

    /**
     * @param list<int> $rowNumbers
     */
    private static function listRowNumbers(array $rowNumbers): string
    {
        $shown = array_slice($rowNumbers, 0, 10);
        $list = implode(', ', $shown);

        if (count($rowNumbers) > count($shown)) {
            $list .= ', …';
        }

        return $list;
    }

    /**
     * @param array<string> $values
     */
    private static function quotedList(array $values): string
    {
        return '"' . implode('", "', $values) . '"';
    }

    /**
     * @param array<string, string|int|TranslatableMessage> $parameters
     */
    private static function message(string $key, array $parameters = []): TranslatableMessage
    {
        return new TranslatableMessage(self::MESSAGE_PREFIX . $key, $parameters);
    }

    private function findOrCreateTeam(CompetitionRound $round, string $teamName): CompetitionTeam
    {
        /** @var false|string $existingTeamId */
        $existingTeamId = $this->database->executeQuery(
            'SELECT id FROM competition_team WHERE round_id = :roundId AND lower(name) = lower(:name) ORDER BY id LIMIT 1',
            ['roundId' => $round->id->toString(), 'name' => $teamName],
        )->fetchOne();

        if ($existingTeamId !== false) {
            $team = $this->entityManager->find(CompetitionTeam::class, $existingTeamId);
            assert($team instanceof CompetitionTeam);

            return $team;
        }

        $team = new CompetitionTeam(
            id: Uuid::uuid7(),
            round: $round,
            name: $teamName,
        );

        $this->entityManager->persist($team);

        return $team;
    }
}
