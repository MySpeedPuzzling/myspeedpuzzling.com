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
    #[Column(options: ['default' => 0])]
    public int $solvedTimesCount = 0;

    #[Column(nullable: true)]
    public null|int $fastestTime = null;

    #[Column(nullable: true)]
    public null|int $averageTime = null;

    #[Column(nullable: true)]
    public null|int $slowestTime = null;

    // Solo
    #[Column(options: ['default' => 0])]
    public int $solvedTimesSoloCount = 0;

    #[Column(nullable: true)]
    public null|int $fastestTimeSolo = null;

    #[Column(nullable: true)]
    public null|int $averageTimeSolo = null;

    #[Column(nullable: true)]
    public null|int $slowestTimeSolo = null;

    // Duo
    #[Column(options: ['default' => 0])]
    public int $solvedTimesDuoCount = 0;

    #[Column(nullable: true)]
    public null|int $fastestTimeDuo = null;

    #[Column(nullable: true)]
    public null|int $averageTimeDuo = null;

    #[Column(nullable: true)]
    public null|int $slowestTimeDuo = null;

    // Team
    #[Column(options: ['default' => 0])]
    public int $solvedTimesTeamCount = 0;

    #[Column(nullable: true)]
    public null|int $fastestTimeTeam = null;

    #[Column(nullable: true)]
    public null|int $averageTimeTeam = null;

    #[Column(nullable: true)]
    public null|int $slowestTimeTeam = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[OneToOne(targetEntity: Puzzle::class)]
        #[JoinColumn(name: 'puzzle_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
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
