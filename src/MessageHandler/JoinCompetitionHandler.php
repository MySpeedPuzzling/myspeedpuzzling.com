<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantAlreadyConnectedToDifferentPlayer;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\RegistrationNotOpen;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Query\GetCompetitionRegistrationOverview;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\ClaimedResultReverter;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
readonly final class JoinCompetitionHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private CompetitionParticipantRoundRepository $participantRoundRepository,
        private CompetitionRepository $competitionRepository,
        private CompetitionTeamRepository $teamRepository,
        private PlayerRepository $playerRepository,
        private GetCompetitionParticipants $getCompetitionParticipants,
        private GetCompetitionRegistrationOverview $getCompetitionRegistrationOverview,
        private Connection $database,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private PlayerAccountEmail $playerAccountEmail,
        private ClaimedResultReverter $claimedResultReverter,
    ) {
    }

    /**
     * @throws CompetitionParticipantNotFound
     * @throws CompetitionParticipantAlreadyConnectedToDifferentPlayer
     * @throws RegistrationNotOpen
     */
    public function __invoke(JoinCompetition $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $competition = $this->competitionRepository->get($message->competitionId);

        if ($competition->registrationManaged === true && $competition->isRegistrationOpen($this->clock->now()) === false) {
            throw new RegistrationNotOpen();
        }

        if ($message->teamId !== null) {
            // PORT-TODO: PR #136 team join was written before main's join rework (release of other rows, validate
            // before mutating, pairs & teams) - review joinTeam() against those rules
            $this->joinTeam($message, $competition, $player);

            return;
        }

        if ($message->participantId !== null) {
            $participant = $this->participantRepository->get($message->participantId);

            // Validate everything before touching the player's current rows: a rolled-back handler
            // leaves its changed entities in the entity manager, and the next flush of the same
            // request would still write them
            if ($participant->competition->id->toString() !== $message->competitionId || $participant->isDeleted()) {
                throw new CompetitionParticipantNotFound();
            }

            if ($participant->player !== null && $participant->player->id->equals($player->id) === false) {
                throw new CompetitionParticipantAlreadyConnectedToDifferentPlayer();
            }

            $this->releaseOtherParticipants($message->competitionId, $player, keep: $participant);
            $participant->connect($player, $this->clock->now());

            // Organizer-listed participants may already carry a status (organizer is
            // authoritative); only register when none exists yet
            if ($competition->registrationManaged === true && $participant->registrationStatus === null) {
                // A non-deleted row with NULL status is already counted as active in the DB
                $this->applyRegistration($competition, $participant, $player, alreadyCountedAsActive: $participant->isDeleted() === false);
            }

            return;
        }

        if ($this->getCompetitionParticipants->isPlayerSelfJoined($message->competitionId, $message->playerId)) {
            return;
        }

        // "Not on the list" — whatever organizer's row the player was connected to is not them
        $this->releaseOtherParticipants($message->competitionId, $player, keep: null);

        $existingId = $this->findSoftDeletedSelfJoin($message->competitionId, $message->playerId);

        if ($existingId !== null) {
            $existing = $this->participantRepository->get($existingId);
            $existing->restore();
            $existing->connect($player, $this->clock->now());

            // Re-registration after cancelling starts fresh — capacity may have filled meanwhile.
            // The DB row still has deleted_at set (flush happens after the handler), so it is
            // not part of the active count.
            if ($competition->registrationManaged === true) {
                $this->applyRegistration($competition, $existing, $player, alreadyCountedAsActive: false);
            }

            return;
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

        if ($competition->registrationManaged === true) {
            // New row is not flushed yet, so it is not part of the active count
            $this->applyRegistration($competition, $participant, $player, alreadyCountedAsActive: false);
        }
    }

    /**
     * A self-joined row is the player's own and goes away; an organizer's row stays on their list.
     */
    private function releaseOtherParticipants(string $competitionId, Player $player, null|CompetitionParticipant $keep): void
    {
        $connections = $this->getCompetitionParticipants->getPlayerConnections($competitionId, $player->id->toString());

        $released = array_filter(
            $connections,
            static fn (string $participantId): bool => $keep === null || $keep->id->toString() !== $participantId,
        );

        if ($released !== []) {
            // Switching identity un-claims materialized results of the old identity
            // PORT-TODO: PR #136 reverted on every identity switch of a picked participant; with main's release rule
            // this now also runs for "not on the list" - confirm, and that it reverts only the released identities
            $this->claimedResultReverter->revertForPlayerInCompetition($player->id->toString(), $competitionId);
        }

        foreach ($connections as $participantId) {
            if ($keep !== null && $keep->id->toString() === $participantId) {
                continue;
            }

            $participant = $this->participantRepository->get($participantId);

            if ($participant->source === ParticipantSource::SelfJoined) {
                $participant->softDelete($this->clock->now());
            } else {
                $participant->disconnect();
            }
        }
    }

    /**
     * "I was in team X" — connects the player to the competition (creating a
     * self-joined participant when needed) and assigns them to the team's round
     * and team. This is how team members without a participant record claim
     * their spot (organizers often only know team names).
     */
    private function joinTeam(JoinCompetition $message, Competition $competition, Player $player): void
    {
        $team = $this->teamRepository->get((string) $message->teamId);
        $round = $team->round;

        if ($round->competition->id->equals($competition->id) === false) {
            return;
        }

        $participant = $this->findOrCreateOwnParticipant($message, $competition, $player);

        // Ensure round assignment with the team set
        $participantRoundId = $this->findParticipantRound($participant->id->toString(), $round->id->toString());

        if ($participantRoundId !== null) {
            $participantRound = $this->participantRoundRepository->get($participantRoundId);
            $participantRound->assignToTeam($team);

            return;
        }

        $this->participantRoundRepository->save(new CompetitionParticipantRound(
            id: Uuid::uuid7(),
            participant: $participant,
            round: $round,
            team: $team,
        ));
    }

    private function findOrCreateOwnParticipant(JoinCompetition $message, Competition $competition, Player $player): CompetitionParticipant
    {
        $existingId = $this->findActiveParticipantOfPlayer($message->competitionId, $message->playerId);

        if ($existingId !== null) {
            return $this->participantRepository->get($existingId);
        }

        $softDeletedId = $this->findSoftDeletedSelfJoin($message->competitionId, $message->playerId);

        if ($softDeletedId !== null) {
            $existing = $this->participantRepository->get($softDeletedId);
            $existing->restore();
            $existing->connect($player, $this->clock->now());

            if ($competition->registrationManaged === true) {
                $this->applyRegistration($competition, $existing, $player, alreadyCountedAsActive: false);
            }

            return $existing;
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

        if ($competition->registrationManaged === true) {
            $this->applyRegistration($competition, $participant, $player, alreadyCountedAsActive: false);
        }

        return $participant;
    }

    private function findActiveParticipantOfPlayer(string $competitionId, string $playerId): null|string
    {
        $query = <<<SQL
SELECT id FROM competition_participant
WHERE competition_id = :competitionId
AND player_id = :playerId
AND deleted_at IS NULL
LIMIT 1
SQL;

        /** @var false|string $result */
        $result = $this->database->executeQuery($query, [
            'competitionId' => $competitionId,
            'playerId' => $playerId,
        ])->fetchOne();

        return $result !== false ? $result : null;
    }

    private function findParticipantRound(string $participantId, string $roundId): null|string
    {
        /** @var false|string $result */
        $result = $this->database->executeQuery(
            'SELECT id FROM competition_participant_round WHERE participant_id = :participantId AND round_id = :roundId LIMIT 1',
            ['participantId' => $participantId, 'roundId' => $roundId],
        )->fetchOne();

        return $result !== false ? $result : null;
    }

    private function applyRegistration(
        Competition $competition,
        CompetitionParticipant $participant,
        Player $player,
        bool $alreadyCountedAsActive,
    ): void {
        $activeCount = $this->getCompetitionRegistrationOverview->countActiveRegistrations($competition->id->toString());

        if ($alreadyCountedAsActive === true) {
            $activeCount -= 1;
        }

        $status = RegistrationStatus::Reserved;

        if ($competition->capacity !== null && $activeCount >= $competition->capacity) {
            $status = RegistrationStatus::Waitlisted;
        }

        $participant->register($status, $this->clock->now());

        $this->sendRegistrationEmail($competition, $participant, $player, $status);
    }

    private function sendRegistrationEmail(
        Competition $competition,
        CompetitionParticipant $participant,
        Player $player,
        RegistrationStatus $status,
    ): void {
        // PORT-TODO: player.email was dropped on main - the address is user_account.email (PlayerAccountEmail)
        $playerEmail = $this->playerAccountEmail->ofPlayer($player);

        if ($playerEmail === null) {
            return;
        }

        $playerLocale = $player->locale ?? 'en';

        // PORT-TODO: an edition's page is edition_detail, not event_detail (main: CompetitionDetailUrl)
        $eventUrl = $this->urlGenerator->generate('event_detail', [
            'slug' => $competition->slug,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $template = $status === RegistrationStatus::Waitlisted
            ? 'emails/competition_registration_waitlisted.html.twig'
            : 'emails/competition_registration_reserved.html.twig';

        $subjectKey = $status === RegistrationStatus::Waitlisted
            ? 'competition_registration_waitlisted.subject'
            : 'competition_registration_reserved.subject';

        $subject = $this->translator->trans(
            $subjectKey,
            ['%competitionName%' => $competition->name],
            domain: 'emails',
            locale: $playerLocale,
        );

        $email = (new TemplatedEmail())
            ->to($playerEmail)
            ->locale($playerLocale)
            ->subject($subject)
            ->htmlTemplate($template)
            ->context([
                'competitionName' => $competition->name,
                'eventUrl' => $eventUrl,
                'entryFeeText' => $competition->entryFeeText,
                'paymentInstructions' => $competition->paymentInstructions,
                // The participant's own row is not flushed yet, so they are the newest
                // waitlist entry: position = currently persisted waitlisted count + 1
                'waitlistPosition' => $status === RegistrationStatus::Waitlisted
                    ? $this->getCompetitionRegistrationOverview->countByStatus($competition->id->toString())['waitlisted'] + 1
                    : null,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
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
