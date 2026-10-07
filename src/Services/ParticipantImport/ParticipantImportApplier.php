<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\ParticipantImportPreviewStale;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use SpeedPuzzling\Web\Results\ParticipantImportPlan;
use SpeedPuzzling\Web\Results\ParticipantImportResult;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantImportOperations;
use SpeedPuzzling\Web\Value\ParticipantSource;

/**
 * Writes what ParticipantImportPlanner planned - through the entities, persist only: the caller's transaction
 * flushes (ApplyParticipantImportHandler; the console façade flushes itself). Ids of new participants, teams and
 * round entries are generated here, never at plan time.
 *
 * Every entity the plan references is loaded before the first change: one that is gone meanwhile (somebody deleted
 * it outside the import's lock) throws ParticipantImportPreviewStale with nothing changed.
 */
readonly final class ParticipantImportApplier
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompetitionRepository $competitionRepository,
        private CompetitionTeamRepository $competitionTeamRepository,
        private CompetitionParticipantRoundRepository $participantRoundRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ParticipantImportPreviewStale
     */
    public function apply(string $competitionId, ParticipantImportPlan $plan): ParticipantImportResult
    {
        $operations = $plan->operations;
        assert($operations instanceof ParticipantImportOperations);

        $competition = $this->competitionRepository->get($competitionId);
        $now = $this->clock->now();

        // 1. Everything the plan references - nothing is changed yet

        /** @var array<string, CompetitionParticipant> $existing participant id => participant */
        $existing = [];
        /** @var array<string, Player> $players */
        $players = [];
        foreach ($operations->participants as $operation) {
            if (!str_starts_with($operation['key'], 'new:')) {
                $existing[$operation['key']] = $this->find(CompetitionParticipant::class, $operation['key']);
            }
            if ($operation['connectPlayerId'] !== null) {
                $players[$operation['connectPlayerId']] = $this->find(Player::class, $operation['connectPlayerId']);
            }
        }
        foreach ($operations->newEntries as $operation) {
            if (!str_starts_with($operation['participantKey'], 'new:') && !isset($existing[$operation['participantKey']])) {
                $existing[$operation['participantKey']] = $this->find(CompetitionParticipant::class, $operation['participantKey']);
            }
        }

        /** @var array<string, CompetitionRound> $rounds */
        $rounds = [];
        foreach ([...$operations->newTeams, ...$operations->newEntries] as $operation) {
            $rounds[$operation['roundId']] ??= $this->find(CompetitionRound::class, $operation['roundId']);
        }

        /** @var array<string, CompetitionTeam> $teams existing team id => team */
        $teams = [];
        foreach ([...$operations->newEntries, ...$operations->entryTeams] as $operation) {
            if ($operation['team'] !== null && str_starts_with($operation['team'], 't:')) {
                $teamId = substr($operation['team'], 2);
                $teams[$teamId] ??= $this->find(CompetitionTeam::class, $teamId);
            }
        }
        foreach ($operations->deletedTeams as $teamId) {
            $teams[$teamId] ??= $this->find(CompetitionTeam::class, $teamId);
        }

        /** @var array<string, CompetitionParticipantRound> $entries */
        $entries = [];
        foreach ($operations->entryTeams as $operation) {
            $entries[$operation['entryId']] = $this->find(CompetitionParticipantRound::class, $operation['entryId']);
        }
        foreach ($operations->deletedEntries as $entryId) {
            $entries[$entryId] ??= $this->find(CompetitionParticipantRound::class, $entryId);
        }

        // 2. The changes

        /** @var array<string, CompetitionParticipant> $participants key => participant */
        $participants = $existing;
        foreach ($operations->participants as $operation) {
            if (str_starts_with($operation['key'], 'new:')) {
                $participant = new CompetitionParticipant(
                    id: Uuid::uuid7(),
                    name: $operation['name'],
                    country: $operation['country'],
                    competition: $competition,
                    source: ParticipantSource::Imported,
                );
                $this->entityManager->persist($participant);
            } else {
                $participant = $existing[$operation['key']];

                if ($participant->name !== $operation['name']) {
                    $participant->updateName($operation['name']);
                }
                if ($participant->country !== $operation['country']) {
                    $participant->updateCountry($operation['country']);
                }
            }

            if ($operation['externalId'] !== $participant->externalId) {
                $participant->updateExternalId($operation['externalId']);
            }

            if ($operation['connectPlayerId'] !== null) {
                $participant->connect($players[$operation['connectPlayerId']], $now);
            }

            if ($operation['markAsImported']) {
                $participant->markAsImported();
            }

            if ($operation['restore']) {
                $participant->restore();
            }

            if ($operation['softDelete']) {
                $participant->softDelete($now);
            }

            $participants[$operation['key']] = $participant;
        }

        /** @var array<string, CompetitionTeam> $newTeams key => team */
        $newTeams = [];
        foreach ($operations->newTeams as $operation) {
            $team = new CompetitionTeam(
                id: Uuid::uuid7(),
                round: $rounds[$operation['roundId']],
                name: $operation['name'],
            );
            $this->competitionTeamRepository->save($team);
            $newTeams[$operation['key']] = $team;
        }

        $teamOf = static function (null|string $key) use ($newTeams, $teams): null|CompetitionTeam {
            if ($key === null) {
                return null;
            }

            if (str_starts_with($key, 't:')) {
                return $teams[substr($key, 2)];
            }

            return $newTeams[$key] ?? throw new \LogicException(sprintf('The plan references team "%s" it does not create.', $key));
        };

        foreach ($operations->newEntries as $operation) {
            $participant = $participants[$operation['participantKey']]
                ?? throw new \LogicException(sprintf('The plan references participant "%s" it does not create.', $operation['participantKey']));

            $this->participantRoundRepository->save(new CompetitionParticipantRound(
                id: Uuid::uuid7(),
                participant: $participant,
                round: $rounds[$operation['roundId']],
                team: $teamOf($operation['team']),
            ));
        }

        foreach ($operations->entryTeams as $operation) {
            $entry = $entries[$operation['entryId']];
            $team = $teamOf($operation['team']);

            if ($team === null) {
                $entry->removeFromTeam();
            } else {
                $entry->assignToTeam($team);
            }
        }

        foreach ($operations->deletedEntries as $entryId) {
            $this->participantRoundRepository->delete($entries[$entryId]);
        }

        // Teams the import empties - members incl. hidden removed ones let go first, like DeleteCompetitionTeamHandler
        foreach ($operations->deletedTeams as $teamId) {
            $team = $teams[$teamId];

            foreach ($this->participantRoundRepository->findByTeam($team) as $entry) {
                // findByTeam() reads the database: an entry this import already moved elsewhere is not the team's
                if ($entry->team === $team) {
                    $entry->removeFromTeam();
                }
            }

            $this->competitionTeamRepository->delete($team);
        }

        return new ParticipantImportResult(
            added: $operations->added,
            updated: $operations->updated,
            softDeleted: $operations->softDeleted,
            unchanged: $operations->unchanged,
            restored: $operations->restored,
            removed: $operations->removed,
            roundEntriesRemoved: count($operations->deletedEntries),
            teamsRemoved: count($operations->deletedTeams),
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     *
     * @throws ParticipantImportPreviewStale
     */
    private function find(string $class, string $id): object
    {
        return $this->entityManager->find($class, $id) ?? throw new ParticipantImportPreviewStale();
    }
}
