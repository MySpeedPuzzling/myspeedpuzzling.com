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
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\StudioInviteStatus;

/**
 * An invitation to the studio of an online round (docs/features/online-competitions/README.md §5.12 "The studio flow",
 * contract phase-1b.md §4): the host asks a finisher to come on air (InviteToStudio), the participant joins or declines
 * (AnswerStudioInvite), the host thanks them (EndStudioGuest) - or the guest leaves the studio (the LiveKit webhook,
 * RecordStudioGuestPresence).
 *
 * - One open invitation per participant and round: `invited` (not expired yet) or `joined`.
 * - Unanswered for EXPIRES_AFTER_SECONDS it is expired - checked on read (statusAt()); the stored status says
 *   `expired` only once something wrote it (a new invitation of the participant, an end).
 * - `onAirFrom` is when LiveKit saw the guest in the studio (its webhook), `onAirTo` when they left or were thanked.
 *
 * Changed only through join(), decline(), end(), expire() and cameOnAir().
 */
#[Entity]
class StudioInvite
{
    public const int EXPIRES_AFTER_SECONDS = 120;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 16, enumType: StudioInviteStatus::class)]
    public StudioInviteStatus $status = StudioInviteStatus::Invited;

    // When the participant joined or declined
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $answeredAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $onAirFrom = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $onAirTo = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public CompetitionRound $round,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public CompetitionParticipant $participant,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Player $invitedBy,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $invitedAt,
    ) {
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->invitedAt->modify('+' . self::EXPIRES_AFTER_SECONDS . ' seconds');
    }

    /**
     * The status as it is at $now: an invitation nobody answered in time is expired, whatever is stored
     */
    public function statusAt(DateTimeImmutable $now): StudioInviteStatus
    {
        if ($this->status === StudioInviteStatus::Invited && $now >= $this->expiresAt()) {
            return StudioInviteStatus::Expired;
        }

        return $this->status;
    }

    /**
     * Waiting for the participant's answer, or on air - the participant can not be invited again meanwhile
     */
    public function isOpenAt(DateTimeImmutable $now): bool
    {
        $status = $this->statusAt($now);

        return $status === StudioInviteStatus::Invited || $status === StudioInviteStatus::Joined;
    }

    /**
     * The participant comes on air - the studio token is theirs from now on (only from `invited`, AnswerStudioInvite
     * checks the expiry)
     */
    public function join(DateTimeImmutable $at): void
    {
        if ($this->status === StudioInviteStatus::Invited) {
            $this->status = StudioInviteStatus::Joined;
            $this->answeredAt = $at;
        }
    }

    public function decline(DateTimeImmutable $at): void
    {
        if ($this->status === StudioInviteStatus::Invited) {
            $this->status = StudioInviteStatus::Declined;
            $this->answeredAt = $at;
        }
    }

    /**
     * The host's "Thank you", or the guest left the studio: off air. An invitation not answered yet is withdrawn
     * (ended, never on air).
     */
    public function end(DateTimeImmutable $at): void
    {
        if ($this->status === StudioInviteStatus::Joined) {
            $this->status = StudioInviteStatus::Ended;
            $this->onAirTo = $at;
        } elseif ($this->status === StudioInviteStatus::Invited) {
            $this->status = StudioInviteStatus::Ended;
        }
    }

    /**
     * Written once something comes across an invitation that ran out unanswered (statusAt())
     */
    public function expire(): void
    {
        if ($this->status === StudioInviteStatus::Invited) {
            $this->status = StudioInviteStatus::Expired;
        }
    }

    /**
     * LiveKit saw the guest in the studio - the first time counts (the webhook may come again, a phone may reconnect)
     */
    public function cameOnAir(DateTimeImmutable $at): void
    {
        if ($this->status === StudioInviteStatus::Joined && $this->onAirFrom === null) {
            $this->onAirFrom = $at;
        }
    }
}
