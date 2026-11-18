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
use SpeedPuzzling\Web\Value\EmailSuppressionSource;
use SpeedPuzzling\Web\Value\MailboxFullRule;

/**
 * An address the receiving side refused for good (an SES permanent bounce, or a domain that does not exist): no
 * notification is sent to it any more and a player with this address sees a banner asking for a working one.
 * Transactional mail is still tried - except for an address blocked by hand (source manual), which gets nothing.
 * Keyed by the address, not by a player - it covers guests (newsletter) too, and a player who changes the address
 * is free of it at once. Listmonk blocklists the address on its own for the newsletter.
 */
#[Entity]
class EmailSuppression
{
    /**
     * The person marked a claim invitation to the address as spam (docs/features/competitions-management/
     * participant-linking.md R8) - kept whatever the source becomes (a full inbox pausing notifications, a bounce), so no
     * claim invitation goes to the address again even after a full inbox is lifted
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $invitationsComplainedAt = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        /** Lowercased and trimmed */
        #[Immutable]
        #[Column(length: 320, unique: true)]
        public string $email,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $suppressedAt,
        /** SES bounceSubType: General, NoEmail, Suppressed, OnAccountSuppressionList, UnsubscribedRecipient */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 50, nullable: true)]
        public null|string $bounceSubType,
        /** The receiving server's answer, e.g. "smtp; 550 5.1.1 user unknown" */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $diagnosticCode,
        /** What we were sending when it bounced (email_audit_log.email_type, or "newsletter") */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 100, nullable: true)]
        public null|string $emailType,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::STRING, length: 30, enumType: EmailSuppressionSource::class, options: ['default' => 'hard_bounce'])]
        public EmailSuppressionSource $source = EmailSuppressionSource::HardBounce,
        /** Blocked by hand: why, in words the player reads in the banner */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $reason = null,
    ) {
    }

    public static function blockedByHand(UuidInterface $id, string $email, DateTimeImmutable $at, string $reason): self
    {
        return new self($id, self::canonicalize($email), $at, null, null, null, EmailSuppressionSource::Manual, $reason);
    }

    /**
     * Nothing at all goes to the address, sign-in links included - only when blocked by hand
     */
    public function blocksAllMail(): bool
    {
        return $this->source === EmailSuppressionSource::Manual;
    }

    /**
     * Blocked by hand on top of a bounce (or again with a new reason): from now on nothing is sent
     */
    public function blockByHand(DateTimeImmutable $at, string $reason): void
    {
        $this->suppressedAt = $at;
        $this->source = EmailSuppressionSource::Manual;
        $this->reason = $reason;
    }

    /**
     * Still holds mail back: a full inbox pauses notifications for MailboxFullRule::EXPIRES_AFTER_DAYS only, every
     * other suppression stays until the address changes (readers in SQL: MailboxFullRule::sqlInEffect())
     */
    public function isInEffect(DateTimeImmutable $now): bool
    {
        // A complaint about a claim invitation stops claim invitations only (holdsBackClaimInvitations())
        if ($this->source === EmailSuppressionSource::InvitationComplaint) {
            return false;
        }

        return $this->source !== EmailSuppressionSource::MailboxFull
            || $this->suppressedAt > $now->modify(sprintf('-%d days', MailboxFullRule::EXPIRES_AFTER_DAYS));
    }

    /**
     * No claim invitation goes to the address (participant-linking.md R8): every suppression in effect, and a complaint
     * about an earlier invitation
     */
    public function holdsBackClaimInvitations(DateTimeImmutable $now): bool
    {
        return $this->invitationsComplainedAt !== null
            || $this->source === EmailSuppressionSource::InvitationComplaint
            || $this->isInEffect($now);
    }

    public static function invitationComplaint(UuidInterface $id, string $email, DateTimeImmutable $at): self
    {
        $suppression = new self($id, self::canonicalize($email), $at, null, null, 'participant_claim_invitation', EmailSuppressionSource::InvitationComplaint);
        $suppression->invitationsComplainedAt = $at;

        return $suppression;
    }

    /**
     * A complaint about a claim invitation to an address already on the list (a full inbox, a bounce, a block): its
     * source stays (a full inbox keeps pausing notifications), the complaint is kept beside it
     */
    public function complainedAboutInvitation(DateTimeImmutable $at): void
    {
        $this->invitationsComplainedAt ??= $at;
    }

    /**
     * A delivery lifts a full inbox (isLiftedByDelivery()) - with a complaint about an invitation kept, the row stays as
     * that complaint instead of going away. True = the row may be removed.
     */
    public function liftFullInbox(DateTimeImmutable $at): bool
    {
        if ($this->invitationsComplainedAt === null) {
            return true;
        }

        $this->source = EmailSuppressionSource::InvitationComplaint;
        $this->suppressedAt = $at;

        return false;
    }

    /**
     * A full inbox is the only suppression a delivery to the address lifts
     */
    public function isLiftedByDelivery(): bool
    {
        return $this->source === EmailSuppressionSource::MailboxFull;
    }

    public static function canonicalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * The address bounced again - keep the latest reason. A block by hand stays one: only the internal API lifts it.
     * A full inbox that now bounces for good (no such user, no such domain) becomes that suppression.
     */
    public function bouncedAgain(DateTimeImmutable $at, null|string $bounceSubType, null|string $diagnosticCode, null|string $emailType, null|EmailSuppressionSource $source = null): void
    {
        if ($this->source !== EmailSuppressionSource::Manual) {
            $this->suppressedAt = $at;
        }

        // A weaker suppression (a full inbox, a complaint about an invitation) becomes the bounce
        if (
            in_array($this->source, [EmailSuppressionSource::MailboxFull, EmailSuppressionSource::InvitationComplaint], true)
            && $source !== null
            && $source !== EmailSuppressionSource::Manual
        ) {
            $this->source = $source;
        }

        $this->bounceSubType = $bounceSubType;
        $this->diagnosticCode = $diagnosticCode;
        $this->emailType = $emailType;
    }
}
