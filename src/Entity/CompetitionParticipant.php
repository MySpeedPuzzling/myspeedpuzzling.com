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
use SpeedPuzzling\Web\Value\ClaimInvitationSkip;
use SpeedPuzzling\Web\Value\ParticipantEmail;
use SpeedPuzzling\Web\Value\ParticipantLinkMethod;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RegistrationStatus;

#[Entity]
class CompetitionParticipant
{
    public const int ORGANIZER_NOTE_MAX_LENGTH = 255;

    /** At most one claim invitation per person in this time (participant-linking.md R8) */
    public const string CLAIM_INVITATION_EVERY = '24 hours';

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $connectedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne]
    public null|Player $player = null;

    /**
     * How the current link was made (docs/features/competitions-management/participant-linking.md §3.1) - written by
     * ParticipantLinks only (ParticipantLinkCoverageTest). Null = not linked, or linked before this was recorded.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, length: 20, nullable: true, enumType: ParticipantLinkMethod::class)]
    public null|ParticipantLinkMethod $connectedVia = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $remoteId = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $externalId = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $deletedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, nullable: true, enumType: RegistrationStatus::class)]
    public null|RegistrationStatus $registrationStatus = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $registeredAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $paidAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $checkedInAt = null;

    /** Private to the event's maintainers, never shown publicly */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: self::ORGANIZER_NOTE_MAX_LENGTH, nullable: true)]
    public null|string $organizerNote = null;

    /**
     * The personal link of an online competition (OnlineToken, docs/features/online-competitions/README.md §5.4): who
     * holds it competes as this participant - the token is the access right. Null until the organiser turns online
     * timing on; assignOnlineToken() again = a new link, the old one stops working.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 32, unique: true, nullable: true)]
    public null|string $onlineToken = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $onlineTokenCreatedAt = null;

    /**
     * The connected player showed that the personal link is theirs (docs/features/online-competitions/README.md §5.4):
     * they opened it signed in (ConfirmOnlineLink, from compete_connect) or made the row themselves ("I'm going",
     * JoinCompetitionHandler). Only then does the event page hand them the link ("Open my competition page") - a row
     * claimed from the organiser's list is no proof of holding its link. Every new connection clears it (connect(),
     * disconnect()); hasConfirmedOnlineLink() also checks it is not older than the connection.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $onlineLinkConfirmedAt = null;

    /**
     * The personal claim link's secret (docs/features/competitions-management/participant-linking.md D5, §3.1 -
     * OnlineToken's format): who opens `/claim/{token}` signed in is linked to this participant at once, under every claim
     * policy - the organiser handed it to one person. Made on demand for unlinked people (GenerateParticipantClaimTokens),
     * a new one in place of the old with "New link" (RegenerateParticipantClaimToken). An unlink clears it only when the
     * link being undone was made with it (R6, disconnect()) - a used link never links a second person, a printed slip of
     * somebody the organiser linked by hand keeps working.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 32, unique: true, nullable: true)]
    public null|string $claimToken = null;

    /**
     * The organiser's address for the person (participant-linking.md D15, §3.1) - private like the note: only the
     * event's organisers see it, used only for the claim invitation. Canonical (ParticipantEmail). Erased 60 days after
     * the event's last day (EraseParticipantEmails).
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: ParticipantEmail::MAX_LENGTH, nullable: true)]
    public null|string $email = null;

    /** The last claim invitation sent to the person (SendParticipantClaimInvitations) */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $claimInvitedAt = null;

    /** The person's one-click "no more invitations for this event" (RefuseParticipantClaimInvitations) */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $claimInvitationsRefusedAt = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $country,
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Competition $competition,
        #[Column(type: Types::STRING, enumType: ParticipantSource::class, options: ['default' => 'imported'])]
        public ParticipantSource $source = ParticipantSource::Imported,
    ) {
    }

    /**
     * Call through ParticipantLinks (it logs the link - ParticipantLinkCoverageTest scans src/). The method defaults to
     * `organiser` only for tests and fixtures setting up an organiser's list (R12): every link of the application passes
     * its own, and `organiser` keeps what such a link may do today (a link made after a round started may still propose
     * a time - R3 refuses only `claim` and unrecorded links).
     */
    public function connect(Player $player, DateTimeImmutable $connectedAt, ParticipantLinkMethod $via = ParticipantLinkMethod::Organiser): void
    {
        $this->player = $player;
        $this->connectedAt = $connectedAt;
        $this->connectedVia = $via;
        // Whoever is connected now has not shown they hold the personal link yet
        $this->onlineLinkConfirmedAt = null;
    }

    /**
     * Already linked to this player - re-picking your own participant changes nothing (connect() would move
     * connectedAt to now, and a link newer than a round's start may not propose times - TimeProposalOwnership, P1F).
     */
    public function isConnectedTo(Player $player): bool
    {
        return $this->player !== null && $this->player->id->equals($player->id);
    }

    /**
     * Call through ParticipantLinks. Whatever the link was made with goes with it: the claim token only when the link was
     * made with it (`claim_link`, R6) - a used claim link never links a second person; any other link leaves the token
     * as it is, so the slip the organiser printed or the link they sent keeps working for the right person.
     */
    public function disconnect(): void
    {
        if ($this->connectedVia === ParticipantLinkMethod::ClaimLink) {
            $this->claimToken = null;
        }

        $this->player = null;
        $this->connectedAt = null;
        $this->connectedVia = null;
        $this->onlineLinkConfirmedAt = null;
    }

    public function updateRemoteId(string $remoteId): void
    {
        $this->remoteId = $remoteId;
    }

    public function updateExternalId(null|string $externalId): void
    {
        $this->externalId = $externalId;
    }

    public function updateName(string $name): void
    {
        $this->name = $name;
    }

    public function updateCountry(null|string $country): void
    {
        $this->country = $country;
    }

    public function softDelete(DateTimeImmutable $deletedAt): void
    {
        $this->deletedAt = $deletedAt;
    }

    public function restore(): void
    {
        $this->deletedAt = null;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    /**
     * A self-joined player found on the organizer's list: from now on the row is the organizer's,
     * so leaving the event disconnects it instead of deleting it.
     */
    public function markAsImported(): void
    {
        $this->source = ParticipantSource::Imported;
    }

    /**
     * A new registration to an event with managed registration (docs/features/competitions-management/registration.md):
     * reserved, or waitlisted when the event is full. A registration made again after cancelling starts fresh - not paid,
     * not checked in, at the end of the queue - but keeps when it was paid before: the organiser's record of a payment
     * they hold is never wiped by the player (the participants sheet shows it, "Mark paid" confirms it again).
     */
    public function register(RegistrationStatus $status, DateTimeImmutable $registeredAt): void
    {
        $this->registrationStatus = $status;
        $this->registeredAt = $registeredAt;
        $this->checkedInAt = null;
    }

    /**
     * A waitlist exists only on an event that manages registration (CompetitionParticipantGoing): a waitlisted row that
     * comes back on an event that does not - joining again, the organiser's restore - holds a spot like every other
     * "I'm going".
     */
    public function leaveWaitlistOfUnmanagedEvent(): void
    {
        if ($this->registrationStatus === RegistrationStatus::Waitlisted) {
            $this->registrationStatus = RegistrationStatus::Reserved;
        }
    }

    /**
     * Rows without a status (on the list before registration was managed, imported, "I'm going" before) hold a spot -
     * they read as reserved.
     */
    public function effectiveRegistrationStatus(): RegistrationStatus
    {
        return $this->registrationStatus ?? RegistrationStatus::Reserved;
    }

    public function markPaid(DateTimeImmutable $paidAt): void
    {
        $this->registrationStatus = RegistrationStatus::Paid;
        $this->paidAt = $paidAt;
    }

    public function unmarkPaid(): void
    {
        $this->registrationStatus = RegistrationStatus::Reserved;
        $this->paidAt = null;
    }

    /**
     * The one "is going" rule (CompetitionParticipantGoing) on the entity: not removed from the event, not waiting on
     * its waitlist.
     */
    public function isGoing(): bool
    {
        return $this->isDeleted() === false && $this->registrationStatus !== RegistrationStatus::Waitlisted;
    }

    public function promoteFromWaitlist(): void
    {
        $this->registrationStatus = RegistrationStatus::Reserved;
    }

    public function checkIn(DateTimeImmutable $checkedInAt): void
    {
        $this->checkedInAt = $checkedInAt;
    }

    public function undoCheckIn(): void
    {
        $this->checkedInAt = null;
    }

    public function updateOrganizerNote(null|string $organizerNote): void
    {
        $this->organizerNote = $organizerNote;
    }

    /**
     * A personal link for an online competition - also a new one in place of the old (GenerateOnlineTokens,
     * RegenerateOnlineToken). The caller makes the token (OnlineToken::generate()).
     */
    public function assignOnlineToken(string $token, DateTimeImmutable $at): void
    {
        $this->onlineToken = $token;
        $this->onlineTokenCreatedAt = $at;
    }

    /**
     * A personal claim link - also a new one in place of the old ("New link"): the old one stops working at once. The
     * caller makes the token (OnlineToken::generate()).
     */
    public function assignClaimToken(string $token): void
    {
        $this->claimToken = $token;
    }

    /**
     * The organiser's address for the person - canonicalised here too (ParticipantEmail), null or blank = none. The
     * callers validated it (the import's `invalid_email`, the sheet's refusal).
     */
    public function updateEmail(null|string $email): void
    {
        $email = $email === null ? '' : ParticipantEmail::canonicalize($email);
        $this->email = $email === '' ? null : $email;
    }

    public function recordClaimInvitation(DateTimeImmutable $at): void
    {
        $this->claimInvitedAt = $at;
    }

    /**
     * The person's "no more invitations for this event" - kept as the first moment they said it (one-click again
     * changes nothing)
     */
    public function refuseClaimInvitations(DateTimeImmutable $at): void
    {
        $this->claimInvitationsRefusedAt ??= $at;
    }

    /**
     * Why the person gets no claim invitation now (participant-linking.md §5.5, R8) - null = they may get one: an address,
     * on the list, not linked to a profile, they did not say no, and the last invitation is more than
     * CLAIM_INVITATION_EVERY ago. Whether the address takes our e-mails (email_suppression) is the sender's check.
     */
    public function claimInvitationRefusal(DateTimeImmutable $now): null|ClaimInvitationSkip
    {
        return match (true) {
            $this->isDeleted() => ClaimInvitationSkip::NotOnList,
            $this->player !== null => ClaimInvitationSkip::Linked,
            $this->email === null => ClaimInvitationSkip::NoEmail,
            $this->claimInvitationsRefusedAt !== null => ClaimInvitationSkip::SaidNo,
            $this->claimInvitedAt !== null && $this->claimInvitedAt > $now->modify('-' . self::CLAIM_INVITATION_EVERY) => ClaimInvitationSkip::InvitedRecently,
            default => null,
        };
    }

    public function mayBeInvited(DateTimeImmutable $now): bool
    {
        return $this->claimInvitationRefusal($now) === null;
    }

    /**
     * The connected player showed the personal link is theirs (see $onlineLinkConfirmedAt) - call it after connect()
     */
    public function confirmOnlineLink(DateTimeImmutable $at): void
    {
        $this->onlineLinkConfirmedAt = $at;
    }

    /**
     * The player connected now confirmed the personal link for this very connection - never one confirmed before it
     */
    public function hasConfirmedOnlineLink(): bool
    {
        return $this->player !== null
            && $this->connectedAt !== null
            && $this->onlineLinkConfirmedAt !== null
            && $this->onlineLinkConfirmedAt >= $this->connectedAt;
    }
}
