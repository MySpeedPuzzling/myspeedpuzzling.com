<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * The candidate model's taste projection of a puzzle's current picture over its 16 picture factors - each row of W
 * times the factors (TasteModel::factorSums(), the first terms of the taste vector [1, W x]), stored for the one reader
 * that needs it for every puzzle at once: the Puzzle Picker's SQL centre while the candidate is the shown model
 * (CandidateCentreSql; 64 multiply-adds and 16 JSON reads per puzzle otherwise). Read only while it is current: the same
 * picture (image_fingerprint), the same estimate push (estimate_received_at), the same factors basis and the same fit's W
 * (taste_fitted_at) - else the SQL computes it from the factors itself, so the Picker always agrees with the cards.
 *
 * Written by the candidate's own jobs only (CandidatePictureProjections - in SQL, a row per picture with factors of the
 * model's basis): the daily fit (W changes) and the 15-minute evidence refresh (new pushes). No foreign key: a merged or
 * removed puzzle's row is dropped by the next run.
 */
#[Entity]
class CandidatePictureProjection
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $puzzleId,
        #[Immutable]
        #[Column(length: 64)]
        public string $imageFingerprint,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $estimateReceivedAt,
        #[Immutable]
        #[Column(length: 50)]
        public string $factorsVersion,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $tasteFittedAt,
        #[Immutable]
        #[Column]
        public float $factorSum1,
        #[Immutable]
        #[Column]
        public float $factorSum2,
        #[Immutable]
        #[Column]
        public float $factorSum3,
        #[Immutable]
        #[Column]
        public float $factorSum4,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $computedAt,
    ) {
    }
}
