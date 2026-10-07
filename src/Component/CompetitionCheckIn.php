<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Message\CheckInParticipant;
use SpeedPuzzling\Web\Message\MarkParticipantPaid;
use SpeedPuzzling\Web\Message\UndoParticipantCheckIn;
use SpeedPuzzling\Web\Query\GetCompetitionParticipantsForManagement;
use SpeedPuzzling\Web\Results\ManageableCompetitionParticipant;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Event-day check-in (managed registration, docs/features/competitions-management/registration.md): everybody holding
 * a spot - the waitlist is not here. Every action re-checks the maintainer and looks the participant up in this event.
 */
#[AsLiveComponent]
final class CompetitionCheckIn
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $competitionId = '';

    #[LiveProp(writable: true)]
    public string $searchQuery = '';

    /** @var array<ManageableCompetitionParticipant> */
    public array $participants = [];

    public int $checkedInCount = 0;
    public int $totalCount = 0;

    public function __construct(
        private readonly GetCompetitionParticipantsForManagement $getParticipants,
        private readonly MessageBusInterface $messageBus,
        private readonly Security $security,
    ) {
    }

    /**
     * Every Live request is a request of its own - a maintainer removed meanwhile must not keep checking people in.
     */
    #[PostHydrate]
    public function denyAccessUnlessMaintainer(): void
    {
        if (!$this->security->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $this->competitionId)) {
            throw new AccessDeniedHttpException();
        }
    }

    #[PostMount]
    #[PreReRender]
    public function loadData(): void
    {
        $this->participants = array_values(array_filter(
            $this->getParticipants->all($this->competitionId),
            static fn (ManageableCompetitionParticipant $participant): bool => $participant->registrationStatus !== RegistrationStatus::Waitlisted,
        ));

        $this->totalCount = count($this->participants);
        $this->checkedInCount = count(array_filter(
            $this->participants,
            static fn (ManageableCompetitionParticipant $participant): bool => $participant->checkedInAt !== null,
        ));
    }

    /**
     * @return array<ManageableCompetitionParticipant>
     */
    public function getVisibleParticipants(): array
    {
        $query = mb_strtolower(trim($this->searchQuery));

        if ($query === '') {
            return $this->participants;
        }

        return array_values(array_filter(
            $this->participants,
            static fn (ManageableCompetitionParticipant $participant): bool => str_contains(mb_strtolower($participant->participantName), $query)
                || ($participant->playerName !== null && str_contains(mb_strtolower($participant->playerName), $query)),
        ));
    }

    #[LiveAction]
    public function checkIn(#[LiveArg] string $participantId): void
    {
        // Throws for a participant of another competition
        $participant = $this->getParticipants->byId($this->competitionId, $participantId);

        $this->messageBus->dispatch(new CheckInParticipant(
            competitionId: $this->competitionId,
            participantId: $participant->participantId,
        ));
    }

    #[LiveAction]
    public function undoCheckIn(#[LiveArg] string $participantId): void
    {
        $participant = $this->getParticipants->byId($this->competitionId, $participantId);

        $this->messageBus->dispatch(new UndoParticipantCheckIn(
            competitionId: $this->competitionId,
            participantId: $participant->participantId,
        ));
    }

    #[LiveAction]
    public function markPaid(#[LiveArg] string $participantId): void
    {
        $participant = $this->getParticipants->byId($this->competitionId, $participantId);

        $this->messageBus->dispatch(new MarkParticipantPaid(
            competitionId: $this->competitionId,
            participantId: $participant->participantId,
        ));
    }
}
