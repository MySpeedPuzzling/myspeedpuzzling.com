<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantAlreadyConnectedToDifferentPlayer;
use SpeedPuzzling\Web\Exceptions\RegistrationNotOpen;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Query\GetCompetitionRegistrationOverview;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
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
        private CompetitionRepository $competitionRepository,
        private PlayerRepository $playerRepository,
        private GetCompetitionParticipants $getCompetitionParticipants,
        private GetCompetitionRegistrationOverview $getCompetitionRegistrationOverview,
        private Connection $database,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    /**
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

        if ($message->participantId !== null) {
            // Picking from organizer's list — disconnect existing, connect to selected
            $this->disconnectExisting($message->competitionId, $message->playerId);

            $participant = $this->participantRepository->get($message->participantId);

            if ($participant->player !== null && $participant->player->id->equals($player->id) === false) {
                throw new CompetitionParticipantAlreadyConnectedToDifferentPlayer();
            }

            $participant->connect($player, $this->clock->now());

            // Organizer-listed participants may already carry a status (organizer is
            // authoritative); only register when none exists yet
            if ($competition->registrationManaged === true && $participant->registrationStatus === null) {
                // A non-deleted row with NULL status is already counted as active in the DB
                $this->applyRegistration($competition, $participant, $player, alreadyCountedAsActive: $participant->isDeleted() === false);
            }

            return;
        }

        // Self-join — check for soft-deleted record to restore
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

        // Create new self-join participant
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
        if ($player->email === null) {
            return;
        }

        $playerLocale = $player->locale ?? 'en';

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
            ->to($player->email)
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

    private function disconnectExisting(string $competitionId, string $playerId): void
    {
        $connections = $this->getCompetitionParticipants->getPlayerConnections($competitionId, $playerId);

        foreach ($connections as $participantId) {
            $participant = $this->participantRepository->get($participantId);
            $participant->disconnect();
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
