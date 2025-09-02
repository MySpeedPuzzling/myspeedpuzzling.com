<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Query\GetCompetitionRounds;
use SpeedPuzzling\Web\Results\ConnectedCompetitionParticipant;
use SpeedPuzzling\Web\Results\NotConnectedCompetitionParticipant;
use SpeedPuzzling\Web\Results\CompetitionRoundInfo;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
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

    #[LiveProp(writable: true)]
    public bool $firstTryOnly = false;

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
        readonly private ChartBuilderInterface $chartBuilder,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[PostMount]
    #[PreReRender]
    public function populate(): void
    {
        $this->competitionRounds = $this->getCompetitionRounds->ofCompetition($this->competitionId);
        $this->participantsRounds = $this->getCompetitionRounds->forAllCompetitionParticipants($this->competitionId, $this->roundsFilter);
        $this->connectedParticipants = $this->getCompetitionParticipants->getConnectedParticipants($this->competitionId, $this->roundsFilter, $this->firstTryOnly);
        $this->notConnectedParticipants = $this->getCompetitionParticipants->getNotConnectedParticipants($this->competitionId, $this->roundsFilter);
    }

    #[LiveAction]
    public function filterRound(#[LiveArg] string $roundId): void
    {
        $key = array_search($roundId, $this->roundsFilter, true);

        if ($key !== false) {
            // Remove from filter if already present
            unset($this->roundsFilter[$key]);
            $this->roundsFilter = [];
        } else {
            // Add to filter if not present
            $this->roundsFilter = [$roundId];
        }
    }

    public function getActiveFiltersCount(): int
    {
        $count = 0;

        if ($this->firstTryOnly !== false) {
            $count++;
        }

        return $count;
    }

    public function getChart(): Chart
    {
        $labels = [];
        $chartData = [];
        $backgrounds = [];

        // Filter participants with valid average time
        $participantsWithAverageTime = array_filter(
            array: $this->connectedParticipants,
            callback: fn (ConnectedCompetitionParticipant $participant): bool => $participant->averageTime !== null
        );

        foreach ($participantsWithAverageTime as $index => $participant) {
            $labels[] = sprintf(
                '%d. %s',
                $index + 1,
                $participant->participantName
            );

            $chartData[] = $participant->averageTime;
            $backgrounds[] = 'rgba(105, 179, 254, 0.6)';

            if ($this->retrieveLoggedUserProfile->getProfile()?->playerId === $participant->playerId) {
                $backgrounds[] = 'rgba(254, 64, 66, 1)';
            } elseif ($this->firstTryOnly === true) {
                $backgrounds[] = 'rgba(105, 179, 254, 0.6)';
            } else {
                $backgrounds[] = 'rgba(254, 105, 106, 0.6)';
            }
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'backgroundColor' => $backgrounds,
                    'data' => $chartData,
                ],
            ],
        ]);

        $chart->setOptions([
            'indexAxis' => 'y', // Horizontal bar chart
            'scales' => [
                'x' => [
                    'grid' => [
                        'display' => true,
                    ],
                ],
                'y' => [
                    'grid' => [
                        'display' => false,
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
        ]);

        return $chart;
    }
}
