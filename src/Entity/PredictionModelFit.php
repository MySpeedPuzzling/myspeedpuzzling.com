<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;

/**
 * The fitted parameters of one prediction model version (PredictionModelParameters as JSON): the shrinkage tau, the
 * thin-size rules, the window variance model and the calibration quantile table. One row per
 * TimePredictionCalculator::MODEL_VERSION, refitted once a day by the insights batch (PredictionModelFitter), read
 * through PredictionModelParametersProvider (cached). Written in SQL by the batch only.
 */
#[Entity]
class PredictionModelFit
{
    public function __construct(
        // TimePredictionCalculator::MODEL_VERSION
        #[Id]
        #[Immutable]
        #[Column(type: Types::SMALLINT)]
        public int $modelVersion,
        #[Immutable]
        #[Column]
        public DateTimeImmutable $fittedAt,
        /** @var array<string, mixed> */
        #[Immutable]
        #[Column(type: Types::JSON, options: ['jsonb' => true])]
        public array $parameters,
    ) {
    }
}
