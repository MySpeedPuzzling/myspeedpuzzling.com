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
use SpeedPuzzling\Web\Value\ResultReviewContactSkipReason;
use SpeedPuzzling\Web\Value\ResultReviewContactStatus;
use SpeedPuzzling\Web\Value\ResultReviewContactType;

/**
 * One "Your results" e-mail to one player (docs/features/duplicate-results.md, "Telling players"): planned by the
 * daily planning, sent (or skipped) by the paced sending job, then followed - did the player open the review page
 * from it, did they resolve anything after it.
 *
 * The case and removal ids are what the e-mail is (or was) about - a case appears in at most one sent e-mail ever.
 * Plain ids without FKs, like the tables they point to.
 */
#[Entity]
#[Index(columns: ['player_id', 'status'])]
#[Index(columns: ['status', 'sent_at'])]
class ResultReviewContact
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, enumType: ResultReviewContactStatus::class)]
    public ResultReviewContactStatus $status = ResultReviewContactStatus::Planned;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $sentAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, nullable: true, enumType: ResultReviewContactSkipReason::class)]
    public null|ResultReviewContactSkipReason $skippedReason = null;

    // The first visit of the review page through this e-mail's link
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $pageVisitedAt = null;

    // The first time the player resolved anything after this e-mail (while it was their latest one)
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $reactedAt = null;

    /**
     * @param list<string> $caseIds
     * @param list<string> $removalIds
     * @param list<string> $suspiciousNoticeIds
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        #[Immutable]
        #[Column(type: Types::STRING, enumType: ResultReviewContactType::class)]
        public ResultReviewContactType $type,
        // Sending order, lower first: weekly 0, first to an active player 1, first to a dormant one 2
        #[Immutable]
        #[Column(type: Types::SMALLINT)]
        public int $priority,
        // Within a priority, the most recently active first (the waves of the backlog)
        #[Immutable]
        #[Column(type: Types::DATE_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $lastActiveOn,
        // Narrowed at send time to what was still open
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSON)]
        public array $caseIds,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSON)]
        public array $removalIds,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $plannedAt,
        // suspicious_time_notice ids: marks and moderators' answers the e-mail tells (docs/features/suspicious-time-review.md, "E-mail")
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSONB, options: ['default' => '[]'])]
        public array $suspiciousNoticeIds = [],
    ) {
    }

    public function isPlanned(): bool
    {
        return $this->status === ResultReviewContactStatus::Planned;
    }

    /**
     * @param list<string> $caseIds what the e-mail really listed
     * @param list<string> $removalIds
     * @param list<string> $suspiciousNoticeIds the marks and moderators' answers it told
     */
    public function sent(array $caseIds, array $removalIds, DateTimeImmutable $now, array $suspiciousNoticeIds = []): void
    {
        $this->status = ResultReviewContactStatus::Sent;
        $this->caseIds = $caseIds;
        $this->removalIds = $removalIds;
        $this->suspiciousNoticeIds = $suspiciousNoticeIds;
        $this->sentAt = $now;
    }

    public function skip(ResultReviewContactSkipReason $reason): void
    {
        $this->status = ResultReviewContactStatus::Skipped;
        $this->skippedReason = $reason;
    }

    public function pageVisited(DateTimeImmutable $now): void
    {
        if ($this->status !== ResultReviewContactStatus::Sent || $this->pageVisitedAt !== null) {
            return;
        }

        $this->pageVisitedAt = $now;
    }

    public function reacted(DateTimeImmutable $now): void
    {
        if ($this->status !== ResultReviewContactStatus::Sent || $this->reactedAt !== null) {
            return;
        }

        $this->reactedAt = $now;
    }
}
