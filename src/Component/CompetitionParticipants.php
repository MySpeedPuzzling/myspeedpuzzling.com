<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Query\GetCompetitionRounds;
use SpeedPuzzling\Web\Results\ConnectedCompetitionParticipant;
use SpeedPuzzling\Web\Results\NotConnectedCompetitionParticipant;
use SpeedPuzzling\Web\Results\CompetitionRoundInfo;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsLiveComponent]
final class CompetitionParticipants
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $competitionId = '';

    #[LiveProp]
    public string $eventSlug = '';

    /** @var array<string> */
    #[LiveProp(writable: true)]
    public array $roundsFilter = [];

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
    #[PreReRender]
    public function populate(): void
    {
        $this->competitionRounds = $this->getCompetitionRounds->ofCompetition($this->competitionId);
        $this->participantsRounds = $this->getCompetitionRounds->forAllCompetitionParticipants($this->competitionId, $this->roundsFilter);
        $this->connectedParticipants = $this->getCompetitionParticipants->getConnectedParticipants($this->competitionId, $this->roundsFilter);
        $this->notConnectedParticipants = $this->getCompetitionParticipants->getNotConnectedParticipants($this->competitionId, $this->roundsFilter);
    }

    #[LiveAction]
    public function filterRound(#[LiveArg] string $roundId): void
    {
        $key = array_search($roundId, $this->roundsFilter, true);

        if ($key !== false) {
            // Remove from filter if already present
            unset($this->roundsFilter[$key]);
            $this->roundsFilter = array_values($this->roundsFilter); // Re-index array
        } else {
            // Add to filter if not present
            $this->roundsFilter[] = $roundId;
        }
    }
}
