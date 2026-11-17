<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\LinkRequestKind;
use SpeedPuzzling\Web\Value\LinkRequestStatus;

/**
 * A player's claim of a competition participant waiting for the organiser (docs/features/competitions-management/
 * participant-linking.md §3.3, D4): a pending request is no link - `competition_participant.player_id` stays as it is
 * until the organiser approves, so it unlocks nothing. Written by ParticipantLinks only, under CompetitionParticipantsLock;
 * "one pending request per player per event" is kept there (a newer claim supersedes the older one).
 *
 * `playerName` / `playerCode`: the claimant's own name and #code at the claim - shown to the event's organisers to decide
 * it, even for a private profile (the claim screen says so: consented disclosure, PrivateProfileQueryCoverageTest's one
 * documented exemption).
 */
#[Entity]
#[Index(columns: ['competition_id', 'status'])]
#[Index(columns: ['participant_id', 'status'])]
#[Index(columns: ['player_id', 'competition_id'])]
class ParticipantLinkRequest
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, length: 20, enumType: LinkRequestStatus::class)]
    public LinkRequestStatus $status = LinkRequestStatus::Pending;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $decidedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne]
    #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public null|Player $decidedBy = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Competition $competition,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public CompetitionParticipant $participant,
        // The claimant - account deletion removes their requests
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        #[Immutable]
        #[Column(type: Types::STRING, length: 20, enumType: LinkRequestKind::class)]
        public LinkRequestKind $kind,
        #[Immutable]
        #[Column]
        public string $playerName,
        #[Immutable]
        #[Column]
        public string $playerCode,
        // Managed events: the claimant's explicit yes to giving up their own (paid) registration, asked at the claim
        // (`release_paid`) - the release happens at approval (R1)
        #[Immutable]
        #[Column(options: ['default' => false])]
        public bool $releasesOwnRegistration,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
    }

    public function isPending(): bool
    {
        return $this->status === LinkRequestStatus::Pending;
    }

    public function approve(null|Player $by, DateTimeImmutable $now): void
    {
        $this->decide(LinkRequestStatus::Approved, $by, $now);
    }

    public function decline(null|Player $by, DateTimeImmutable $now): void
    {
        $this->decide(LinkRequestStatus::Declined, $by, $now);
    }

    public function withdraw(DateTimeImmutable $now): void
    {
        $this->decide(LinkRequestStatus::Withdrawn, null, $now);
    }

    public function supersede(DateTimeImmutable $now): void
    {
        $this->decide(LinkRequestStatus::Superseded, null, $now);
    }

    private function decide(LinkRequestStatus $status, null|Player $by, DateTimeImmutable $now): void
    {
        $this->status = $status;
        $this->decidedAt = $now;
        $this->decidedBy = $by;
    }
}
