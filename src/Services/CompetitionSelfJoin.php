<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlinePersonalLinks;
use SpeedPuzzling\Web\Value\ParticipantSource;

/**
 * The one implementation of "the player's own row of an event" (docs/features/competitions-management/participants.md):
 * JoinCompetitionHandler's "I'm going" and the partner finder's going-in-the-same-step (PartnerFinderAttendance) both
 * take the row here. Callers run under CompetitionParticipantsLock (SerializedByLock) and decide the registration status
 * of a managed event themselves, between takeOwnRow() and handOwnLink().
 */
readonly final class CompetitionSelfJoin
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private GetCompetitionParticipants $getCompetitionParticipants,
        private Connection $database,
        private ClockInterface $clock,
        private OfficialResultsGuard $officialResultsGuard,
        private OnlinePersonalLinks $onlinePersonalLinks,
    ) {
    }

    /**
     * Revives the player's soft-deleted self-joined row or creates one - "not on the list": whatever organiser's row the
     * player was connected to is not them and is let go of.
     */
    public function takeOwnRow(Competition $competition, Player $player): CompetitionParticipant
    {
        $competitionId = $competition->id->toString();

        $this->releaseOtherParticipants($competitionId, $player, keep: null);

        $existingId = $this->findSoftDeletedSelfJoin($competitionId, $player->id->toString());

        if ($existingId !== null) {
            $participant = $this->participantRepository->get($existingId);
            $participant->restore();
            $participant->connect($player, $this->clock->now());

            if ($competition->registrationManaged === false) {
                // Left the waitlist while the event managed registration - it no longer does, so they are going
                $participant->leaveWaitlistOfUnmanagedEvent();
            }

            return $participant;
        }

        $participant = new CompetitionParticipant(
            id: Uuid::uuid7(),
            name: $player->name ?? $player->code,
            country: $player->country,
            competition: $competition,
            source: ParticipantSource::SelfJoined,
        );

        $participant->connect($player, $this->clock->now());

        $this->participantRepository->save($participant);

        return $participant;
    }

    /**
     * The row is the player's own (unlike one picked from the organiser's list): the event page may hand them its
     * personal link - given right away when a round of the event uses online timing, while they are going
     * (docs/features/online-competitions/README.md §5.4). Call after the registration status is set.
     */
    public function handOwnLink(CompetitionParticipant $participant): void
    {
        $now = $this->clock->now();
        $participant->confirmOnlineLink($now);
        $this->onlinePersonalLinks->giveOwnLink($participant, $now);
    }

    /**
     * A self-joined row is the player's own and goes away; an organizer's row stays on their list.
     */
    public function releaseOtherParticipants(string $competitionId, Player $player, null|CompetitionParticipant $keep): void
    {
        $connections = $this->getCompetitionParticipants->getPlayerConnections($competitionId, $player->id->toString());

        foreach ($connections as $participantId) {
            if ($keep !== null && $keep->id->toString() === $participantId) {
                continue;
            }

            $participant = $this->participantRepository->get($participantId);

            // A row holding official results is the organiser's record - the player only lets go of it
            if ($participant->source === ParticipantSource::SelfJoined && $this->officialResultsGuard->participantHasOfficialData($participantId) === false) {
                $participant->softDelete($this->clock->now());
            } else {
                $participant->disconnect();
            }
        }
    }

    private function findSoftDeletedSelfJoin(string $competitionId, string $playerId): null|string
    {
        $query = <<<SQL
SELECT id FROM competition_participant
WHERE competition_id = :competitionId
AND player_id = :playerId
AND deleted_at IS NOT NULL
AND source = :source
LIMIT 1
SQL;

        /** @var false|string $result */
        $result = $this->database->executeQuery($query, [
            'competitionId' => $competitionId,
            'playerId' => $playerId,
            'source' => ParticipantSource::SelfJoined->value,
        ])->fetchOne();

        return $result !== false ? $result : null;
    }
}
