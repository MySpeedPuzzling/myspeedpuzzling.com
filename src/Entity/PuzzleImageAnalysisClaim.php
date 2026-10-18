<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\ImageAnalyzer;

/**
 * A worker of the image analysis pipeline is working on this puzzle's image for this analyzer until `expiresAt`
 * (docs/features/internal-api.md, "Puzzle image analysis" - claims): several machines analyse in parallel and never
 * pick the same image for the same analyzer at once. Taken and extended by ClaimPuzzleImagesHandler (one statement,
 * race-safe), deleted when the result is stored (PuzzleImageAnalysisRecorder) or the worker releases it; an expired
 * claim is anybody's to take. Deleted with the puzzle.
 */
#[Entity]
#[UniqueConstraint(columns: ['analyzer', 'puzzle_id'])]
class PuzzleImageAnalysisClaim
{
    public const int MAX_WORKER_LENGTH = 64;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Puzzle $puzzle,
        #[Immutable]
        #[Column(length: 32, enumType: ImageAnalyzer::class)]
        public ImageAnalyzer $analyzer,
        #[Immutable]
        #[Column(length: self::MAX_WORKER_LENGTH)]
        public string $worker,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $claimedAt,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
