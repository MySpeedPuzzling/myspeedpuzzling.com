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
 * The insights batch generation (one row, id 1) - written and read only by Services\Insights\InsightsGeneration.
 */
#[Entity]
class InsightsGeneration
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: Types::SMALLINT)]
        public int $id,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::BIGINT)]
        public int $generation,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public DateTimeImmutable $advancedAt,
    ) {
    }
}
