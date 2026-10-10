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
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;

/**
 * One activity digest or no-activity e-mail of one player about one period - the "at most one e-mail per player and
 * period" anchor (unique player + period) and the memory behind "never two no-activity e-mails in a row".
 *
 * Claimed with INSERT .. ON CONFLICT before the e-mail is sent (ContentDigestLogRepository::claim()), inside the
 * handler's transaction: a second delivery of the same message waits for the first and then gets nothing; a crash
 * rolls the claim back. A `deferred` row (the SMTP server asked us to try later) may be claimed again.
 */
#[Entity]
#[UniqueConstraint(columns: ['player_id', 'period_key'])]
#[Index(columns: ['player_id', 'had_activity'])]
class ContentDigestLog
{
    public const string STATUS_SENT = 'sent';
    public const string STATUS_FAILED_PERMANENT = 'failed_permanent';
    public const string STATUS_DEFERRED = 'deferred';
    /** "We miss you" holdout: deliberately not mailed (MissYouHoldout), logged like a send - once per spell */
    public const string STATUS_HOLDOUT = 'holdout';

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[Immutable]
        public Player $player,
        #[Immutable]
        #[Column(type: Types::STRING, enumType: ContentDigestFrequency::class)]
        public ContentDigestFrequency $frequency,
        #[Immutable]
        #[Column]
        public string $periodKey,
        #[Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public DateTimeImmutable $sentAt,
        /** False = the no-activity e-mail - drives the never-twice rule. */
        #[Column(type: Types::BOOLEAN)]
        public bool $hadActivity,
        #[Column]
        public string $status,
    ) {
    }
}
