<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Results\ParticipantImportResult;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\ParticipantSource;

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
        }

        $this->entityManager->flush();

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
}
