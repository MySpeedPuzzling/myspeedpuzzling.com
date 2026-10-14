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
use SpeedPuzzling\Web\Value\OnlineMomentVerdict;

/**
 * The reviewer's verdict on one finish moment of a participant's online entry (docs/features/online-competitions/
 * README.md §5.9): the moment is the phone's own event - a finish candidate (`finish.candidate`, raised hands its
 * detector held) or a Finished tap (`finish.button`) - and `gap` says its photo shows an unfinished puzzle (the
 * reviewer's G). One row per moment (the unique event id): pressing G again changes nothing, "Not a gap" deletes the
 * row. Who said it and when are kept for the dataset of Phase 3.5 (README §5.8 "Collect the dataset"). Read by the
 * review's marks and the accuracy report only - a suggestion, a confirmation or an official time never reads it.
 */
#[Entity]
#[UniqueConstraint(columns: ['event_id'])]
class OnlineReviewMomentVerdict
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public OnlineEntry $onlineEntry,
        // The moment: the candidate's or the tap's own event
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public OnlineEntryEvent $event,
        #[Immutable]
        #[Column(length: 16, enumType: OnlineMomentVerdict::class)]
        public OnlineMomentVerdict $verdict,
        // The reviewer - kept as long as their account is
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Player $markedBy,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $markedAt,
    ) {
    }
}
