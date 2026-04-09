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
        $teamNameIdx = $this->findColumnIndex($headers, 'team_name');

        if ($nameIdx === null) {
            return new ParticipantImportResult(errors: ['Required column "name" not found.']);
        }

        $existing = $this->loadExistingParticipants($competitionId);

        $added = 0;
        $updated = 0;
        $softDeleted = 0;
        $warnings = [];
        $errors = [];
        $seenNames = [];

        /** @var array<string, array{roundName: string, teamName: null|string}> participantId => assignment */
        $roundAssignments = [];

        $roundsByName = $this->loadRoundsByName($competitionId);

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

            // Track round/team assignment for post-flush processing
            $roundName = $roundNameIdx !== null ? trim((string) ($row[$roundNameIdx] ?? '')) : '';
            $teamName = $teamNameIdx !== null ? trim((string) ($row[$teamNameIdx] ?? '')) : '';

            if ($roundName !== '' && isset($roundsByName[$roundName])) {
                $roundAssignments[$participant->id->toString()] = [
                    'roundName' => $roundName,
                    'teamName' => $teamName !== '' ? $teamName : null,
                ];
            } elseif ($roundName !== '' && !isset($roundsByName[$roundName])) {
                $warnings[] = "Row {$rowNum}: round \"{$roundName}\" not found, skipping round assignment.";
            }
        }

        $this->entityManager->flush();

        // Post-flush: assign participants to rounds and teams
        if ($roundAssignments !== []) {
            $this->processRoundAndTeamAssignments($competitionId, $roundAssignments, $roundsByName);
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
     * @return array<string, CompetitionRound> name => round entity
     */
    private function loadRoundsByName(string $competitionId): array
    {
        $query = <<<SQL
SELECT id FROM competition_round WHERE competition_id = :competitionId
SQL;

        $roundIds = $this->database
            ->executeQuery($query, ['competitionId' => $competitionId])
            ->fetchFirstColumn();

        $rounds = [];
        foreach ($roundIds as $roundId) {
            $round = $this->entityManager->find(CompetitionRound::class, $roundId);
            if ($round !== null) {
                $rounds[$round->name] = $round;
            }
        }

        return $rounds;
    }

    /**
     * @param array<string, array{roundName: string, teamName: null|string}> $assignments
     * @param array<string, CompetitionRound> $roundsByName
     */
    private function processRoundAndTeamAssignments(string $competitionId, array $assignments, array $roundsByName): void
    {
        // Load existing participant-round records
        $existingPr = $this->database->executeQuery(
            'SELECT participant_id, round_id FROM competition_participant_round cpr INNER JOIN competition_participant cp ON cp.id = cpr.participant_id WHERE cp.competition_id = :competitionId',
            ['competitionId' => $competitionId],
        )->fetchAllAssociative();

        /** @var array<string, array<string>> participantId => [roundId, ...] */
        $existingByParticipant = [];
        foreach ($existingPr as $row) {
            /** @var array{participant_id: string, round_id: string} $row */
            $existingByParticipant[$row['participant_id']][] = $row['round_id'];
        }

        /** @var array<string, CompetitionTeam> roundId:teamName => team */
        $teamCache = [];

        foreach ($assignments as $participantId => $assignment) {
            $round = $roundsByName[$assignment['roundName']] ?? null;
            if ($round === null) {
                continue;
            }

            $roundId = $round->id->toString();

            // Skip if already assigned to this round
            if (isset($existingByParticipant[$participantId]) && in_array($roundId, $existingByParticipant[$participantId], true)) {
                continue;
            }

            $participant = $this->entityManager->find(CompetitionParticipant::class, $participantId);
            if ($participant === null) {
                continue;
            }

            // Find or create team if team_name provided and round is duo/team
            $team = null;
            if ($assignment['teamName'] !== null && $round->category !== RoundCategory::Solo) {
                $cacheKey = $roundId . ':' . $assignment['teamName'];
                if (!isset($teamCache[$cacheKey])) {
                    $team = $this->findOrCreateTeam($round, $assignment['teamName']);
                    $teamCache[$cacheKey] = $team;
                } else {
                    $team = $teamCache[$cacheKey];
                }
            }

            $participantRound = new CompetitionParticipantRound(
                id: Uuid::uuid7(),
                participant: $participant,
                round: $round,
                team: $team,
            );

            $this->entityManager->persist($participantRound);
        }
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
