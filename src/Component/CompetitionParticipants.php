<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Query\GetCompetitionRounds;
use SpeedPuzzling\Web\Results\ConnectedCompetitionParticipant;
use SpeedPuzzling\Web\Results\NotConnectedCompetitionParticipant;
use SpeedPuzzling\Web\Results\CompetitionRoundInfo;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsLiveComponent]
final class CompetitionParticipants
{
    use DefaultActionTrait;

    public string $competitionId = '';
    public string $eventSlug = '';

    /** @var array<ConnectedCompetitionParticipant> */
    public array $connectedParticipants = [];

    /** @var array<NotConnectedCompetitionParticipant> */
    public array $notConnectedParticipants = [];

    /** @var array<string, CompetitionRoundInfo> */
    public array $competitionRounds = [];

    /** @var array<string, array<string>> */
    public array $participantsRounds = [];

    public function __construct(
        readonly private GetCompetitionParticipants $getCompetitionParticipants,
        readonly private GetCompetitionRounds $getCompetitionRounds,
    ) {
    }

    #[PostMount]
    public function populate(): void
    {
        $this->connectedParticipants = $this->getCompetitionParticipants->getConnectedParticipants($this->competitionId);
        $this->notConnectedParticipants = $this->getCompetitionParticipants->getNotConnectedParticipants($this->competitionId);
        $this->competitionRounds = $this->getCompetitionRounds->ofCompetition($this->competitionId);
        $this->participantsRounds = $this->getCompetitionRounds->forAllCompetitionParticipants($this->competitionId);
    }
}
