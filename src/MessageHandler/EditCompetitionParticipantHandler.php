<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\OrganizerNoteTooLong;
use SpeedPuzzling\Web\Exceptions\OfficialResultsProtected;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\EditCompetitionParticipant;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\OfficialResultsGuard;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Applies the change of one edit of the participants page (EditCompetitionParticipant) - round entries as a diff against
 * the entries the person has now, under the event's lock: whatever else happened to the person since the row was
 * opened (advanced to a final, seated, connected by the player) stays. Everything is checked before anything changes.
 */
#[AsMessageHandler]
readonly final class EditCompetitionParticipantHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private PlayerRepository $playerRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private OfficialResultsGuard $officialResultsGuard,
    ) {
    }

    /**
     * @throws CompetitionParticipantNotFound a participant of another event
     * @throws PlayerNotFound
     * @throws OrganizerNoteTooLong
     * @throws OfficialResultsProtected taking the person out of a round where they hold official results - nothing changes
     */
    public function __invoke(EditCompetitionParticipant $message): void
    {
        // The column's length - refused before anything changes (the participants page checks it first)
        if ($message->organizerNote !== null && mb_strlen($message->organizerNote) > CompetitionParticipant::ORGANIZER_NOTE_MAX_LENGTH) {
            throw new OrganizerNoteTooLong();
        }

        $participant = $this->participantRepository->getOfCompetition($message->competitionId, $message->participantId);
        $participantId = $participant->id->toString();
        $existing = $this->existingEntriesByRound($participantId);

        $removeRoundIds = array_values(array_unique(array_map('strtolower', $message->removeRoundIds)));
        $entriesToRemove = [];

        foreach ($removeRoundIds as $roundId) {
            $entry = $existing[$roundId] ?? null;

            // Not in the round any more (somebody else took them out meanwhile) - nothing to do
            if ($entry === null) {
                continue;
            }

            // Read from the database under the event's lock - a result recorded a moment ago counts
            if ($this->officialResultsGuard->participantHasOfficialData($participantId, $roundId)) {
                throw new OfficialResultsProtected(OfficialResultsProtected::ENTRY_HAS_RESULT);
            }

            $entriesToRemove[] = $entry;
        }

        $roundsToAdd = [];

        foreach (array_unique(array_map('strtolower', $message->addRoundIds)) as $roundId) {
            // In the round already (added meanwhile, e.g. advanced by the results desk) - it stays as it is
            if (isset($existing[$roundId]) || in_array($roundId, $removeRoundIds, true) || Uuid::isValid($roundId) === false) {
                continue;
            }

            $round = $this->entityManager->find(CompetitionRound::class, $roundId);

            // Only rounds of the participant's own competition
            if ($round === null || !$round->competition->id->equals($participant->competition->id)) {
                continue;
            }

            $roundsToAdd[] = $round;
        }

        $player = $message->changePlayer && $message->playerId !== null
            ? $this->playerRepository->get($message->playerId)
            : null;

        // Everything checked - the changes
        $participant->updateName($message->name);
        $participant->updateCountry($message->country);
        $participant->updateExternalId($message->externalId);

        if ($message->changeOrganizerNote) {
            $participant->updateOrganizerNote($message->organizerNote);
        }

        if ($message->changePlayer) {
            $this->changePlayer($participant, $player);
        }

        foreach ($entriesToRemove as $entry) {
            $this->entityManager->remove($entry);
        }

        foreach ($roundsToAdd as $round) {
            $this->entityManager->persist(new CompetitionParticipantRound(
                id: Uuid::uuid7(),
                participant: $participant,
                round: $round,
            ));
        }
    }

    private function changePlayer(CompetitionParticipant $participant, null|Player $player): void
    {
        if ($player === null) {
            $participant->disconnect();

            return;
        }

        if ($participant->player?->id->equals($player->id) === true) {
            return;
        }

        $participant->disconnect();
        $participant->connect($player, $this->clock->now());
    }

    /**
     * @return array<string, CompetitionParticipantRound> round id => the person's entry
     */
    private function existingEntriesByRound(string $participantId): array
    {
        $entries = [];

        foreach ($this->entityManager->getRepository(CompetitionParticipantRound::class)->findBy(['participant' => $participantId]) as $entry) {
            $entries[$entry->round->id->toString()] = $entry;
        }

        return $entries;
    }
}
