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
use Doctrine\ORM\Mapping\OneToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\OnlineConfirmedSource;
use SpeedPuzzling\Web\Value\OnlineReviewStatus;
use SpeedPuzzling\Web\Value\OnlineUnfinishedMeasure;

/**
 * A participant's online state in one round (docs/features/online-competitions/README.md §5.6, §5.9), created on the
 * first contact of their phone: the device that holds the round, its readiness and clock quality, what the participant
 * reported at time-up, and the review - the confirmed finish moment (epoch ms), where it came from, who confirmed it.
 * The events and the evidence hang off it (OnlineEntryEvent, OnlineEntryMedia).
 *
 * The participant's phone changes the device, readiness, clock and reported fields (RecordOnlineEntryEvents), the review
 * the rest (ConfirmOnlineResult) - through the public properties, confirm() and addFlag().
 */
#[Entity]
class OnlineEntry
{
    // The device that holds the round - the latest one that opened the camera takes over
    #[Column(length: 64, nullable: true)]
    public null|string $activeDeviceId = null;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $readyAt = null;

    // The device's server-clock offset and the round trip of the sample it came from (README §5.2)
    #[Column(type: Types::FLOAT, nullable: true)]
    public null|float $clockOffsetMs = null;

    #[Column(type: Types::FLOAT, nullable: true)]
    public null|float $clockRttMs = null;

    // The official finish moment in epoch milliseconds, once confirmed - null while pending or when unfinished
    #[Column(type: Types::BIGINT, nullable: true)]
    public null|int $confirmedMomentMs = null;

    #[Column(length: 16, nullable: true, enumType: OnlineConfirmedSource::class)]
    public null|OnlineConfirmedSource $confirmedSource = null;

    // What the participant reported at time-up when unfinished - pieces left or placed, the round's measure
    #[Column(length: 16, nullable: true, enumType: OnlineUnfinishedMeasure::class)]
    public null|OnlineUnfinishedMeasure $reportedMeasure = null;

    #[Column(nullable: true)]
    public null|int $reportedPieces = null;

    #[Column(length: 16, enumType: OnlineReviewStatus::class)]
    public OnlineReviewStatus $reviewStatus = OnlineReviewStatus::Pending;

    #[ManyToOne]
    #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public null|Player $reviewedBy = null;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $reviewedAt = null;

    /**
     * What the review should look at ("arrived late", "camera lost", ...) - each flag once, see addFlag()
     *
     * @var list<string>
     */
    #[Column(type: Types::JSONB, options: ['default' => '[]'])]
    public array $flags = [];

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        // The participant in the round (a solo round's entry)
        #[Immutable]
        #[OneToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public CompetitionParticipantRound $entry,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $firstSeenAt,
    ) {
    }

    /**
     * The reviewer's decision: the finish moment (null = unfinished, the pieces go to the official result) and where it
     * came from. Confirming again overwrites the previous decision.
     */
    public function confirm(null|int $momentMs, OnlineConfirmedSource $source, Player $reviewer, DateTimeImmutable $reviewedAt): void
    {
        $this->confirmedMomentMs = $momentMs;
        $this->confirmedSource = $source;
        $this->reviewStatus = OnlineReviewStatus::Confirmed;
        $this->reviewedBy = $reviewer;
        $this->reviewedAt = $reviewedAt;
    }

    public function addFlag(string $flag): void
    {
        if (in_array($flag, $this->flags, true) === false) {
            $this->flags[] = $flag;
        }
    }
}
