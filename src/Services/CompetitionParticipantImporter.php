<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Results\ParticipantImportResult;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RoundCategory;

readonly final class CompetitionParticipantImporter
{
    /**
     * Every column the importer reads. `round_names` (a comma-separated list) is what the template
     * and the export write; `round_name` (one round) is kept for files made before.
     */
    public const array KNOWN_COLUMNS = ['name', 'country', 'external_id', 'msp_player_id', 'status', 'round_names', 'round_name', 'team_name'];

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

        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (empty($rows)) {
            return new ParticipantImportResult(errors: ['Excel file is empty.']);
        }

        /** @var array<string|null> $headerRow */
        $headerRow = array_shift($rows);
        /** @var array<string> $headers */
        $headers = array_map(static fn (null|string $h): string => strtolower(trim((string) $h)), $headerRow);

        $nameIdx = $this->findColumnIndex($headers, 'name');
        $countryIdx = $this->findColumnIndex($headers, 'country');
        $externalIdIdx = $this->findColumnIndex($headers, 'external_id');
        $playerIdIdx = $this->findColumnIndex($headers, 'msp_player_id');
        $statusIdx = $this->findColumnIndex($headers, 'status');
        $roundNameIdx = $this->findColumnIndex($headers, 'round_name');
        $roundNamesIdx = $this->findColumnIndex($headers, 'round_names');
        $teamNameIdx = $this->findColumnIndex($headers, 'team_name');

        if ($nameIdx === null) {
            return new ParticipantImportResult(errors: ['Required column "name" not found.']);
        }

        $unknownColumns = array_values(array_unique(array_filter(
            $headers,
            static fn (string $header): bool => $header !== '' && !in_array($header, self::KNOWN_COLUMNS, true),
        )));

        $existing = $this->loadExistingParticipants($competitionId);

        $added = 0;
        $updated = 0;
        $softDeleted = 0;
        $warnings = [];
        $errors = [];
        $seenNames = [];

        if ($unknownColumns !== []) {
            $warnings[] = sprintf(
                'Unknown column(s) ignored: "%s". Columns the import reads: %s.',
                implode('", "', $unknownColumns),
                implode(', ', self::KNOWN_COLUMNS),
            );
        }

        /**
         * Rows of the same participant add up: every round from every row is assigned.
         *
         * @var array<string, array<string, null|string>> participantId => [roundId => team name]
         */
        $roundAssignments = [];

        /** @var array<string, list<int>> round name as written in the file => row numbers */
        $unknownRoundNames = [];

        $rounds = $this->loadRounds($competitionId);

        foreach ($rows as $rowIndex => $row) {
            /** @var array<int, null|scalar> $row */
            $rowNum = $rowIndex + 2;

            $name = trim((string) ($row[$nameIdx] ?? ''));
            if ($name === '') {
                $errors[] = "Row {$rowNum}: missing name, skipped.";
                continue;
            }

            // Duplicate detection within file
            if (in_array($name, $seenNames, true)) {
                $warnings[] = "Row {$rowNum}: duplicate name \"{$name}\" in file.";
            }
            $seenNames[] = $name;

            $country = $countryIdx !== null ? trim((string) ($row[$countryIdx] ?? '')) : '';
            $externalId = $externalIdIdx !== null ? trim((string) ($row[$externalIdIdx] ?? '')) : '';
            $playerId = $playerIdIdx !== null ? trim((string) ($row[$playerIdIdx] ?? '')) : '';
            $status = $statusIdx !== null ? strtolower(trim((string) ($row[$statusIdx] ?? ''))) : '';

            $countryCode = null;
            if ($country !== '') {
                $countryCode = CountryCode::fromCode($country);
                if ($countryCode === null) {
                    $warnings[] = "Row {$rowNum}: invalid country code \"{$country}\".";
                }
            }

            // Validate player ID
            $validPlayerId = null;
            if ($playerId !== '') {
                if (!Uuid::isValid($playerId)) {
                    $errors[] = "Row {$rowNum}: msp_player_id \"{$playerId}\" is not a valid UUID.";
                } elseif (!$this->playerExists($playerId)) {
                    $errors[] = "Row {$rowNum}: msp_player_id \"{$playerId}\" does not exist.";
                } else {
                    $validPlayerId = $playerId;
                }
            }

            // Match existing participant
            $match = $this->findMatch($existing, $validPlayerId, $externalId !== '' ? $externalId : null, $name, $countryCode?->name);

            if ($match !== null) {
                $participant = $this->entityManager->find(CompetitionParticipant::class, $match['id']);
                assert($participant instanceof CompetitionParticipant);

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
                    $player = $this->entityManager->find(\SpeedPuzzling\Web\Entity\Player::class, $validPlayerId);
                    if ($player !== null) {
                        $participant->connect($player, $this->clock->now());
                    }
                }

                if ($participant->isDeleted()) {
                    $participant->restore();
                }

                if ($status === 'deleted') {
                    $participant->softDelete($this->clock->now());
                    $softDeleted++;
                } else {
                    $updated++;
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
                    $player = $this->entityManager->find(\SpeedPuzzling\Web\Entity\Player::class, $validPlayerId);
                    if ($player !== null) {
                        $participant->connect($player, $this->clock->now());
                    }
                }

                if ($status === 'deleted') {
                    $participant->softDelete($this->clock->now());
                    $softDeleted++;
                } else {
                    $added++;
                }

                $this->entityManager->persist($participant);

                // Add to existing lookup for subsequent row matching
                $existing[] = [
                    'id' => $participant->id->toString(),
                    'name' => $name,
                    'country' => $countryCode?->name,
                    'external_id' => $externalId !== '' ? $externalId : null,
                    'player_id' => $validPlayerId,
                ];
            }

            // Track round/team assignments for post-flush processing
            $teamName = $teamNameIdx !== null ? CompetitionTeam::cleanName((string) ($row[$teamNameIdx] ?? '')) : null;

            if ($teamName !== null && mb_strlen($teamName) > CompetitionTeam::NAME_MAX_LENGTH) {
                $warnings[] = sprintf('Row %d: team name is longer than %d characters, team assignment skipped.', $rowNum, CompetitionTeam::NAME_MAX_LENGTH);
                $teamName = null;
            }

            $requestedRoundNames = [];

            if ($roundNamesIdx !== null) {
                $requestedRoundNames = $this->splitRoundNames((string) ($row[$roundNamesIdx] ?? ''), $rounds);
            }

            if ($roundNameIdx !== null) {
                $requestedRoundNames[] = trim((string) ($row[$roundNameIdx] ?? ''));
            }

            $participantId = $participant->id->toString();

            foreach ($requestedRoundNames as $requestedRoundName) {
                if ($requestedRoundName === '') {
                    continue;
                }

                $round = $rounds[self::roundKey($requestedRoundName)] ?? null;

                if ($round === null) {
                    $unknownRoundNames[$requestedRoundName][] = $rowNum;

                    continue;
                }

                $roundId = $round->id->toString();
                $roundTeamName = $round->category !== RoundCategory::Solo ? $teamName : null;

                if (!isset($roundAssignments[$participantId]) || !array_key_exists($roundId, $roundAssignments[$participantId])) {
                    $roundAssignments[$participantId][$roundId] = $roundTeamName;
                } elseif ($roundAssignments[$participantId][$roundId] === null) {
                    $roundAssignments[$participantId][$roundId] = $roundTeamName;
                } elseif ($roundTeamName !== null && $roundTeamName !== $roundAssignments[$participantId][$roundId]) {
                    $warnings[] = sprintf(
                        'Row %d: "%s" already has team "%s" in round "%s" from an earlier row, team "%s" ignored.',
                        $rowNum,
                        $name,
                        $roundAssignments[$participantId][$roundId],
                        $round->name,
                        $roundTeamName,
                    );
                }
            }
        }

        foreach ($unknownRoundNames as $unknownRoundName => $rowNumbers) {
            $warnings[] = sprintf(
                'Round "%s" does not exist in this event (%s %s), those participants were not assigned to it. Rounds of this event: %s.',
                $unknownRoundName,
                count($rowNumbers) === 1 ? 'row' : 'rows',
                self::listRowNumbers($rowNumbers),
                $rounds === [] ? 'none yet - add the rounds first' : '"' . implode('", "', array_map(
                    static fn (CompetitionRound $round): string => $round->name,
                    array_values($rounds),
                )) . '"',
            );
        }

        $this->entityManager->flush();

        // Post-flush: assign participants to rounds and teams
        if ($roundAssignments !== []) {
            $warnings = [...$warnings, ...$this->processRoundAndTeamAssignments($competitionId, $roundAssignments, $rounds)];
            $this->entityManager->flush();
        }

        return new ParticipantImportResult(
            added: $added,
            updated: $updated,
            softDeleted: $softDeleted,
            warnings: $warnings,
            errors: $errors,
        );
    }

    /**
     * @return array<array{id: string, name: string, country: null|string, external_id: null|string, player_id: null|string}>
     */
    private function loadExistingParticipants(string $competitionId): array
    {
        $query = <<<SQL
SELECT id, name, country, external_id, player_id
FROM competition_participant
WHERE competition_id = :competitionId
-- A player's own "I left" record is not the organizer's data: restoring it would sign them up again
AND NOT (source = 'self_joined' AND deleted_at IS NOT NULL)
-- Active rows first, so a player's live row wins over a soft-deleted one with the same match
ORDER BY deleted_at IS NOT NULL, id
SQL;

        /** @var array<array{id: string, name: string, country: null|string, external_id: null|string, player_id: null|string}> $rows */
        $rows = $this->database->executeQuery($query, ['competitionId' => $competitionId])->fetchAllAssociative();

        return $rows;
    }

    /**
     * @param array<array{id: string, name: string, country: null|string, external_id: null|string, player_id: null|string}> $existing
     * @return null|array{id: string, name: string, country: null|string, external_id: null|string, player_id: null|string}
     */
    private function findMatch(array $existing, null|string $playerId, null|string $externalId, string $name, null|string $country): null|array
    {
        // Priority 1: match by msp_player_id
        if ($playerId !== null) {
            foreach ($existing as $row) {
                if ($row['player_id'] === $playerId) {
                    return $row;
                }
            }
        }

        // Priority 2: match by external_id
        if ($externalId !== null) {
            foreach ($existing as $row) {
                if ($row['external_id'] !== null && $row['external_id'] === $externalId) {
                    return $row;
                }
            }
        }

        // Priority 3: match by name + country
        if ($country !== null) {
            foreach ($existing as $row) {
                if ($row['name'] === $name && $row['country'] === $country) {
                    return $row;
                }
            }
        }

        // Priority 4: unique name match
        $nameMatches = array_filter($existing, fn (array $row): bool => $row['name'] === $name);

        if (count($nameMatches) === 1) {
            return reset($nameMatches);
        }

        return null;
    }

    /**
     * @param array<string> $headers
     */
    private function findColumnIndex(array $headers, string $columnName): null|int
    {
        $key = array_search(strtolower($columnName), $headers, true);

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
     * @return array<string, CompetitionRound> normalised name (see roundKey()) => round entity
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
        foreach ($roundIds as $roundId) {
            $round = $this->entityManager->find(CompetitionRound::class, $roundId);
            if ($round !== null) {
                $rounds[self::roundKey($round->name)] = $round;
            }
        }

        return $rounds;
    }

    /**
     * Import only adds: a participant already in a round stays there with their team. A missing
     * team is filled in; a different team in the file is reported, never switched.
     *
     * @param array<string, array<string, null|string>> $assignments participantId => [roundId => team name]
     * @param array<string, CompetitionRound> $rounds
     * @return array<string> warnings
     */
    private function processRoundAndTeamAssignments(string $competitionId, array $assignments, array $rounds): array
    {
        $roundsById = [];
        foreach ($rounds as $round) {
            $roundsById[$round->id->toString()] = $round;
        }

        // Load existing participant-round records
        $existingPr = $this->database->executeQuery(
            'SELECT cpr.id, cpr.participant_id, cp.name AS participant_name, cpr.round_id, ct.name AS team_name, cpr.team_id
             FROM competition_participant_round cpr
             INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
             LEFT JOIN competition_team ct ON ct.id = cpr.team_id
             WHERE cp.competition_id = :competitionId',
            ['competitionId' => $competitionId],
        )->fetchAllAssociative();

        /** @var array<string, array<string, array{id: string, participant_name: string, team_id: null|string, team_name: null|string}>> participantId => roundId => record */
        $existingByParticipant = [];
        foreach ($existingPr as $row) {
            /** @var array{id: string, participant_id: string, participant_name: string, round_id: string, team_id: null|string, team_name: null|string} $row */
            $existingByParticipant[$row['participant_id']][$row['round_id']] = $row;
        }

        /** @var array<string, CompetitionTeam> roundId:teamName => team */
        $teamCache = [];
        $warnings = [];

        foreach ($assignments as $participantId => $participantRounds) {
            foreach ($participantRounds as $roundId => $teamName) {
                $round = $roundsById[$roundId] ?? null;
                if ($round === null) {
                    continue;
                }

                $existing = $existingByParticipant[$participantId][$roundId] ?? null;

                if ($existing !== null && ($teamName === null || $existing['team_id'] !== null)) {
                    if ($teamName !== null && $existing['team_name'] !== $teamName) {
                        $warnings[] = sprintf(
                            '"%s" stays in team "%s" in round "%s", team "%s" from the file ignored (an import never moves anybody to another team).',
                            $existing['participant_name'],
                            $existing['team_name'] ?? '',
                            $round->name,
                            $teamName,
                        );
                    }

                    continue;
                }

                $team = null;
                if ($teamName !== null) {
                    $cacheKey = $roundId . ':' . $teamName;
                    $team = $teamCache[$cacheKey] ??= $this->findOrCreateTeam($round, $teamName);
                }

                // Already in the round without a team (other cases continued above): fill the team in
                if ($existing !== null) {
                    $participantRound = $this->entityManager->find(CompetitionParticipantRound::class, $existing['id']);
                    $participantRound?->assignToTeam($team);

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
            }
        }

        return $warnings;
    }

    /**
     * A cell of `round_names`: a round's own name when it is one (a round may contain a comma),
     * otherwise a list separated by commas or semicolons.
     *
     * @param array<string, CompetitionRound> $rounds
     * @return list<string>
     */
    private function splitRoundNames(string $cell, array $rounds): array
    {
        $cell = trim($cell);

        if ($cell === '') {
            return [];
        }

        if (isset($rounds[self::roundKey($cell)])) {
            return [$cell];
        }

        return array_values(array_filter(
            array_map(trim(...), preg_split('/[,;]/', $cell) ?: []),
            static fn (string $name): bool => $name !== '',
        ));
    }

    /**
     * Round names match ignoring case and repeated whitespace - "team relay" is "Team Relay".
     */
    private static function roundKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * @param list<int> $rowNumbers
     */
    private static function listRowNumbers(array $rowNumbers): string
    {
        $shown = array_slice($rowNumbers, 0, 10);
        $list = implode(', ', $shown);

        if (count($rowNumbers) > count($shown)) {
            $list .= sprintf(' and %d more', count($rowNumbers) - count($shown));
        }

        return $list;
    }

    private function findOrCreateTeam(CompetitionRound $round, string $teamName): CompetitionTeam
    {
        /** @var false|string $existingTeamId */
        $existingTeamId = $this->database->executeQuery(
            'SELECT id FROM competition_team WHERE round_id = :roundId AND name = :name LIMIT 1',
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
