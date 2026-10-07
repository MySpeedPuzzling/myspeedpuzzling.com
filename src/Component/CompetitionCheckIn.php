<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Message\CheckInParticipant;
use SpeedPuzzling\Web\Message\MarkParticipantPaid;
use SpeedPuzzling\Web\Message\UndoParticipantCheckIn;
use SpeedPuzzling\Web\Query\GetCompetitionParticipantsForManagement;
use SpeedPuzzling\Web\Results\ManageableCompetitionParticipant;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

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
    ) {
    }

    #[PostMount]
    #[PreReRender]
    public function loadData(): void
    {
        $this->participants = $this->getParticipants->all($this->competitionId);

        $this->totalCount = count($this->participants);
        $this->checkedInCount = count(array_filter(
            $this->participants,
            static fn (ManageableCompetitionParticipant $p): bool => $p->checkedInAt !== null,
        ));
    }

    #[LiveAction]
    public function checkIn(#[LiveArg] string $participantId): void
    {
        $this->messageBus->dispatch(new CheckInParticipant(
            participantId: $participantId,
        ));
    }

    #[LiveAction]
    public function undoCheckIn(#[LiveArg] string $participantId): void
    {
        $this->messageBus->dispatch(new UndoParticipantCheckIn(
            participantId: $participantId,
        ));
    }

    #[LiveAction]
    public function markPaid(#[LiveArg] string $participantId): void
    {
        $this->messageBus->dispatch(new MarkParticipantPaid(
            participantId: $participantId,
        ));
    }
}
