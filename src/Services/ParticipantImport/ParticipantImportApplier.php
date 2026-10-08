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
use SpeedPuzzling\Web\Value\RegistrationStatus;

/**
 * Writes what ParticipantImportPlanner (an import) or SheetChangesPlanner (the participants sheet) planned - through
 * the entities, persist only: the caller's transaction flushes (the handlers; the console façade flushes itself). Ids
 * of new import rows are generated here, never at plan time; the sheet's new rows bring the page's own ids.
 *
 * Every entity the operations reference is loaded before the first change, one statement per kind of entity (`IN`),
 * so a write of 400 people reads as much as a write of one: one that is gone meanwhile (somebody deleted it outside
 * the event's lock) throws ParticipantImportPreviewStale with nothing changed.
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

        return $this->applyOperations($competitionId, $operations);
    }

    /**
     * @throws ParticipantImportPreviewStale
     */
    public function applyOperations(string $competitionId, ParticipantImportOperations $operations): ParticipantImportResult
    {
        $competition = $this->competitionRepository->get($competitionId);
        $now = $this->clock->now();

        // 1. Everything the operations reference - nothing is changed yet

        $participantIds = [];
        $playerIds = [];
        foreach ($operations->participants as $operation) {
            if (!str_starts_with($operation['key'], 'new:')) {
                $participantIds[$operation['key']] = true;
            }
            if ($operation['connectPlayerId'] !== null) {
                $playerIds[$operation['connectPlayerId']] = true;
            }
        }
        foreach ($operations->newEntries as $operation) {
            if (!str_starts_with($operation['participantKey'], 'new:')) {
                $participantIds[$operation['participantKey']] = true;
            }
        }

        $roundIds = [];
        foreach ([...$operations->newTeams, ...$operations->newEntries] as $operation) {
            $roundIds[$operation['roundId']] = true;
        }

        $teamIds = [];
        foreach ([...$operations->newEntries, ...$operations->entryTeams] as $operation) {
            if ($operation['team'] !== null && str_starts_with($operation['team'], 't:')) {
                $teamIds[substr($operation['team'], 2)] = true;
            }
        }
        foreach ($operations->deletedTeams as $teamId) {
            $teamIds[$teamId] = true;
        }
        foreach ($operations->renamedTeams as $operation) {
            $teamIds[$operation['teamId']] = true;
        }

        $entryIds = [];
        foreach ($operations->entryTeams as $operation) {
            $entryIds[$operation['entryId']] = true;
        }
        foreach ($operations->deletedEntries as $entryId) {
            $entryIds[$entryId] = true;
        }

        $existing = $this->load(CompetitionParticipant::class, array_keys($participantIds), static fn (CompetitionParticipant $participant): string => $participant->id->toString());
        $players = $this->load(Player::class, array_keys($playerIds), static fn (Player $player): string => $player->id->toString());
        $rounds = $this->load(CompetitionRound::class, array_keys($roundIds), static fn (CompetitionRound $round): string => $round->id->toString());
        $teams = $this->load(CompetitionTeam::class, array_keys($teamIds), static fn (CompetitionTeam $team): string => $team->id->toString());
        $entries = $this->load(CompetitionParticipantRound::class, array_keys($entryIds), static fn (CompetitionParticipantRound $entry): string => $entry->id->toString());
        $membersOfDeletedTeams = $this->entriesOfTeams($operations->deletedTeams);

        // 2. The changes

        /** @var array<string, CompetitionParticipant> $participants key => participant */
        $participants = $existing;
        foreach ($operations->participants as $operation) {
            if (str_starts_with($operation['key'], 'new:')) {
                $participant = new CompetitionParticipant(
                    id: isset($operation['id']) ? Uuid::fromString($operation['id']) : Uuid::uuid7(),
                    name: $operation['name'],
                    country: $operation['country'],
                    competition: $competition,
                    source: isset($operation['source']) ? ParticipantSource::from($operation['source']) : ParticipantSource::Imported,
                );

                // The sheet's new rows on an event managing registration hold a spot (registration.md)
                if (($operation['reserve'] ?? false) === true) {
                    $participant->register(RegistrationStatus::Reserved, $now);
                }

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

            if (array_key_exists('organizerNote', $operation) && $operation['organizerNote'] !== $participant->organizerNote) {
                $participant->updateOrganizerNote($operation['organizerNote']);
            }

            if (($operation['disconnect'] ?? false) === true) {
                $participant->disconnect();
            }

            if ($operation['connectPlayerId'] !== null) {
                $participant->connect($players[$operation['connectPlayerId']], $now);
            }

            if ($operation['markAsImported']) {
                $participant->markAsImported();
            }

            if ($operation['restore']) {
                $participant->restore();

                // No waitlist on an event that does not manage registration (RestoreCompetitionParticipantHandler)
                if (($operation['leaveWaitlist'] ?? false) === true) {
                    $participant->leaveWaitlistOfUnmanagedEvent();
                }
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
                id: isset($operation['id']) ? Uuid::fromString($operation['id']) : Uuid::uuid7(),
                round: $rounds[$operation['roundId']],
                name: $operation['name'],
            );
            $this->competitionTeamRepository->save($team);
            $newTeams[$operation['key']] = $team;
        }

        foreach ($operations->renamedTeams as $operation) {
            $teams[$operation['teamId']]->rename($operation['name']);
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

        // Teams deleted - every member let go first, removed people too (they keep their place in the round, WEB-D5)
        foreach ($operations->deletedTeams as $teamId) {
            $team = $teams[$teamId];

            foreach ($membersOfDeletedTeams[$teamId] ?? [] as $entry) {
                // Read from the database: an entry these operations already moved elsewhere is not the team's
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
     * One statement for every entity of a kind - all of them, or ParticipantImportPreviewStale.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param list<string> $ids
     * @param callable(T): string $idOf
     * @return array<string, T> id => entity
     *
     * @throws ParticipantImportPreviewStale
     */
    private function load(string $class, array $ids, callable $idOf): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<T> $found */
        $found = $this->entityManager->createQueryBuilder()
            ->select('entity')
            ->from($class, 'entity')
            ->where('entity.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($found as $entity) {
            $byId[$idOf($entity)] = $entity;
        }

        foreach ($ids as $id) {
            if (!isset($byId[strtolower($id)])) {
                throw new ParticipantImportPreviewStale();
            }
        }

        // Keyed the way the operations name them
        $keyed = [];
        foreach ($ids as $id) {
            $keyed[$id] = $byId[strtolower($id)];
        }

        return $keyed;
    }

    /**
     * Every entry still pointing at the given teams, as the database has them - also of removed participants the pages
     * hide (WEB-D5).
     *
     * @param list<string> $teamIds
     * @return array<string, list<CompetitionParticipantRound>> team id => entries
     */
    private function entriesOfTeams(array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        /** @var list<CompetitionParticipantRound> $found */
        $found = $this->entityManager->createQueryBuilder()
            ->select('entry')
            ->from(CompetitionParticipantRound::class, 'entry')
            ->where('IDENTITY(entry.team) IN (:teamIds)')
            ->setParameter('teamIds', $teamIds)
            ->getQuery()
            ->getResult();

        $byTeam = [];
        foreach ($found as $entry) {
            if ($entry->team !== null) {
                $byTeam[$entry->team->id->toString()][] = $entry;
            }
        }

        $keyed = [];
        foreach ($teamIds as $teamId) {
            $keyed[$teamId] = $byTeam[strtolower($teamId)] ?? [];
        }

        return $keyed;
    }
}
