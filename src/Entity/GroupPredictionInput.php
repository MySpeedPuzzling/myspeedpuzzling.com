<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use JetBrains\PhpStorm\Immutable;

/**
 * What the stored pair/team predictions of a time (solving_time_prediction, predictors `group` / `group_baseline`)
 * were made for - Group\GroupPredictionModel::fingerprint(): another pair/team, puzzle, day, first-try flag, unboxed or
 * a revealed secret puzzle makes the daily run predict the time again. Whether the rows were made live (by the first
 * daily run after the time was added, with the fit made before it - out of sample) or reconstructed. Written only by
 * the daily run's bulk writer (Group\GroupPredictionRowsWriter); goes with its time.
 */
#[Entity]
class GroupPredictionInput
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[OneToOne]
        #[JoinColumn(name: 'time_id', nullable: false, onDelete: 'CASCADE')]
        public PuzzleSolvingTime $time,
        #[Immutable]
        #[Column(length: 32)]
        public string $fingerprint,
        #[Immutable]
        #[Column]
        public bool $live,
        #[Immutable]
        #[Column]
        public DateTimeImmutable $writtenAt,
    ) {
    }
}
