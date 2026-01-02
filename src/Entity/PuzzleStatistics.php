<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use JetBrains\PhpStorm\Immutable;
use SpeedPuzzling\Web\Value\PuzzleStatisticsData;

#[Entity]
#[Index(columns: ['solved_times_count'])]
#[Index(columns: ['fastest_time'])]
class PuzzleStatistics
{
    // Total
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $solvedTimesCount = 0;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $fastestTime = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $averageTime = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $slowestTime = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $solvedTimesSoloCount = 0;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $fastestTimeSolo = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $averageTimeSolo = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $slowestTimeSolo = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $solvedTimesDuoCount = 0;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $fastestTimeDuo = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $averageTimeDuo = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $slowestTimeDuo = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $solvedTimesTeamCount = 0;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $fastestTimeTeam = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $averageTimeTeam = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $slowestTimeTeam = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[OneToOne]
        #[JoinColumn(onDelete: 'CASCADE')]
        public Puzzle $puzzle,
    ) {
    }

    public function update(PuzzleStatisticsData $data): void
    {
        $this->solvedTimesCount = $data->totalCount;
        $this->fastestTime = $data->fastestTime;
        $this->averageTime = $data->averageTime;
        $this->slowestTime = $data->slowestTime;

        $this->solvedTimesSoloCount = $data->soloCount;
        $this->fastestTimeSolo = $data->fastestTimeSolo;
        $this->averageTimeSolo = $data->averageTimeSolo;
        $this->slowestTimeSolo = $data->slowestTimeSolo;

        $this->solvedTimesDuoCount = $data->duoCount;
        $this->fastestTimeDuo = $data->fastestTimeDuo;
        $this->averageTimeDuo = $data->averageTimeDuo;
        $this->slowestTimeDuo = $data->slowestTimeDuo;

        $this->solvedTimesTeamCount = $data->teamCount;
        $this->fastestTimeTeam = $data->fastestTimeTeam;
        $this->averageTimeTeam = $data->averageTimeTeam;
        $this->slowestTimeTeam = $data->slowestTimeTeam;
    }
}
