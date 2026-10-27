<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;
use SpeedPuzzling\Web\Value\GroupModelParameters;

/**
 * The pair/team prediction model's fit (Value\GroupModelParameters, docs/features/puzzle-intelligence/README.md "Pair
 * and team predictions"), one row per model version - refit daily by `myspeedpuzzling:fit-group-prediction-model`. Its
 * own table: nothing of the shown model (prediction_model_fit) or the candidate changes with it.
 */
#[Entity]
class GroupPredictionFit
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: Types::SMALLINT)]
        public int $modelVersion,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public DateTimeImmutable $fittedAt,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSON, options: ['jsonb' => true])]
        public array $parameters,
    ) {
    }

    public function refit(GroupModelParameters $parameters, DateTimeImmutable $fittedAt): void
    {
        $this->parameters = $parameters->toArray();
        $this->fittedAt = $fittedAt;
    }

    public function parameters(): GroupModelParameters
    {
        return GroupModelParameters::fromArray($this->parameters);
    }
}
