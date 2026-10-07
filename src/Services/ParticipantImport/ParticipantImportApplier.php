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

    public function apply(string $competitionId, ParticipantImportPlan $plan): ParticipantImportResult
    {
        $operations = $plan->operations;
        assert($operations instanceof ParticipantImportOperations);

        $competition = $this->competitionRepository->get($competitionId);
        $now = $this->clock->now();

        /** @var array<string, CompetitionParticipant> $participants key => participant */
        $participants = [];
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
                $participant = $this->participant($operation['key']);

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
                $player = $this->entityManager->find(Player::class, $operation['connectPlayerId']);
                if ($player !== null) {
                    $participant->connect($player, $now);
                }
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
                round: $this->round($operation['roundId']),
                name: $operation['name'],
            );
            $this->competitionTeamRepository->save($team);
            $newTeams[$operation['key']] = $team;
        }

        $teamOf = function (null|string $key) use ($newTeams): null|CompetitionTeam {
            if ($key === null) {
                return null;
            }

            if (str_starts_with($key, 't:')) {
                return $this->competitionTeamRepository->get(substr($key, 2));
            }

            return $newTeams[$key] ?? throw new \LogicException(sprintf('The plan references team "%s" it does not create.', $key));
        };

        foreach ($operations->newEntries as $operation) {
            $participant = $participants[$operation['participantKey']] ?? $this->participant($operation['participantKey']);

            $this->participantRoundRepository->save(new CompetitionParticipantRound(
                id: Uuid::uuid7(),
                participant: $participant,
                round: $this->round($operation['roundId']),
                team: $teamOf($operation['team']),
            ));
        }

        foreach ($operations->entryTeams as $operation) {
            $entry = $this->participantRoundRepository->get($operation['entryId']);
            $team = $teamOf($operation['team']);

            if ($team === null) {
                $entry->removeFromTeam();
            } else {
                $entry->assignToTeam($team);
            }
        }

        foreach ($operations->deletedEntries as $entryId) {
            $this->participantRoundRepository->delete($this->participantRoundRepository->get($entryId));
        }

        // Teams the import empties - members incl. hidden removed ones let go first, like DeleteCompetitionTeamHandler
        foreach ($operations->deletedTeams as $teamId) {
            $team = $this->competitionTeamRepository->get($teamId);

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

    private function participant(string $id): CompetitionParticipant
    {
        $participant = $this->entityManager->find(CompetitionParticipant::class, $id);

        return $participant ?? throw new \LogicException(sprintf('The planned participant "%s" does not exist.', $id));
    }

    private function round(string $id): CompetitionRound
    {
        $round = $this->entityManager->find(CompetitionRound::class, $id);

        return $round ?? throw new \LogicException(sprintf('The planned round "%s" does not exist.', $id));
    }
}
