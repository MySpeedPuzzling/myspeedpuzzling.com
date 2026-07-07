<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Entity\RoundResult;
use SpeedPuzzling\Web\Message\UpsertRoundResult;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use SpeedPuzzling\Web\Repository\RoundResultRepository;
use SpeedPuzzling\Web\Services\RoundResultsPublisher;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class UpsertRoundResultHandler
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private CompetitionParticipantRepository $participantRepository,
        private CompetitionParticipantRoundRepository $participantRoundRepository,
        private CompetitionTeamRepository $teamRepository,
        private RoundResultRepository $resultRepository,
        private Connection $database,
        private ClockInterface $clock,
        private RoundResultsPublisher $publisher,
    ) {
    }

    public function __invoke(UpsertRoundResult $message): void
    {
        $round = $this->roundRepository->get($message->roundId);
        $now = $this->clock->now();

        // Idempotent replay or edit of a known result row
        $result = $this->resultRepository->find($message->resultId);

        if ($result === null) {
            $result = $this->resolveExistingResultForEntrant($round, $message);
        }

        if ($result !== null) {
            $result->updateResult($message->secondsToSolve, $message->missingPieces, $now);
            $this->publisher->publishResultChanged($result);

            return;
        }

        if ($round->category === RoundCategory::Solo) {
            $participant = $this->resolveParticipant($round, $message);
            $team = null;
        } else {
            $participant = null;
            $team = $this->resolveTeam($round, $message);
        }

        $result = new RoundResult(
            id: Uuid::fromString($message->resultId),
            round: $round,
            participant: $participant,
            team: $team,
            secondsToSolve: $message->secondsToSolve,
            missingPieces: $message->missingPieces,
            createdAt: $now,
        );

        $this->resultRepository->save($result);
        $this->publisher->publishResultChanged($result);
    }

    private function resolveExistingResultForEntrant(CompetitionRound $round, UpsertRoundResult $message): null|RoundResult
    {
        $roundId = $round->id->toString();

        if ($message->participantId !== null) {
            return $this->resultRepository->findByRoundAndParticipant($roundId, $message->participantId);
        }

        if ($message->teamId !== null) {
            return $this->resultRepository->findByRoundAndTeam($roundId, $message->teamId);
        }

        if ($message->entrantName === null) {
            return null;
        }

        // Entrant referenced by name — match existing participant/team to keep the upsert idempotent
        if ($round->category === RoundCategory::Solo) {
            $participantId = $this->findParticipantIdByName($round, $message->entrantName);

            return $participantId !== null
                ? $this->resultRepository->findByRoundAndParticipant($roundId, $participantId)
                : null;
        }

        $teamId = $this->findTeamIdByName($round, $message->entrantName);

        return $teamId !== null
            ? $this->resultRepository->findByRoundAndTeam($roundId, $teamId)
            : null;
    }

    private function resolveParticipant(CompetitionRound $round, UpsertRoundResult $message): CompetitionParticipant
    {
        if ($message->participantId !== null) {
            $participant = $this->participantRepository->get($message->participantId);
            $this->ensureAssignedToRound($participant, $round);

            return $participant;
        }

        $name = trim((string) $message->entrantName);
        $existingId = $this->findParticipantIdByName($round, $name);

        if ($existingId !== null) {
            $participant = $this->participantRepository->get($existingId);
            $this->ensureAssignedToRound($participant, $round);

            return $participant;
        }

        $participant = new CompetitionParticipant(
            id: Uuid::uuid7(),
            name: $name,
            country: null,
            competition: $round->competition,
            source: ParticipantSource::Manual,
        );

        if ($round->competition->registrationManaged === true) {
            $participant->register(RegistrationStatus::Reserved, $this->clock->now());
        }

        $this->participantRepository->save($participant);
        $this->assignToRound($participant, $round);

        return $participant;
    }

    private function resolveTeam(CompetitionRound $round, UpsertRoundResult $message): CompetitionTeam
    {
        if ($message->teamId !== null) {
            return $this->teamRepository->get($message->teamId);
        }

        $name = trim((string) $message->entrantName);
        $existingId = $this->findTeamIdByName($round, $name);

        if ($existingId !== null) {
            return $this->teamRepository->get($existingId);
        }

        $team = new CompetitionTeam(
            id: Uuid::uuid7(),
            round: $round,
            name: $name,
        );

        $this->teamRepository->save($team);

        return $team;
    }

    private function findParticipantIdByName(CompetitionRound $round, string $name): null|string
    {
        /** @var false|string $result */
        $result = $this->database->executeQuery(
            'SELECT id FROM competition_participant WHERE competition_id = :competitionId AND LOWER(name) = LOWER(:name) AND deleted_at IS NULL ORDER BY name LIMIT 1',
            [
                'competitionId' => $round->competition->id->toString(),
                'name' => $name,
            ],
        )->fetchOne();

        return $result !== false ? $result : null;
    }

    private function findTeamIdByName(CompetitionRound $round, string $name): null|string
    {
        /** @var false|string $result */
        $result = $this->database->executeQuery(
            'SELECT id FROM competition_team WHERE round_id = :roundId AND LOWER(name) = LOWER(:name) LIMIT 1',
            [
                'roundId' => $round->id->toString(),
                'name' => $name,
            ],
        )->fetchOne();

        return $result !== false ? $result : null;
    }

    private function ensureAssignedToRound(CompetitionParticipant $participant, CompetitionRound $round): void
    {
        /** @var false|string $existing */
        $existing = $this->database->executeQuery(
            'SELECT id FROM competition_participant_round WHERE participant_id = :participantId AND round_id = :roundId LIMIT 1',
            [
                'participantId' => $participant->id->toString(),
                'roundId' => $round->id->toString(),
            ],
        )->fetchOne();

        if ($existing === false) {
            $this->assignToRound($participant, $round);
        }
    }

    private function assignToRound(CompetitionParticipant $participant, CompetitionRound $round): void
    {
        $this->participantRoundRepository->save(new CompetitionParticipantRound(
            id: Uuid::uuid7(),
            participant: $participant,
            round: $round,
        ));
    }
}
