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
use Doctrine\ORM\Mapping\OneToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\ExpectedTimeSource;
use SpeedPuzzling\Web\Value\SuspicionAssessment;
use SpeedPuzzling\Web\Value\SuspicionDirection;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseOrigin;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;
use SpeedPuzzling\Web\Value\SuspiciousTimeTier;

/**
 * One solo time a scan raised or a person flagged (docs/features/suspicious-time-review.md, "Data model"). One row
 * per time, reused for its whole life: a case that is gone, corrected or trusted for an older entry is reopened when
 * the scan raises the time again.
 *
 * puzzle_solving_time.suspicious stays the single source of truth for "no time figure uses it" - the case only
 * explains how the flag got there. Who decided is kept as a plain id (the moderator may delete their account; the
 * decision log has their name).
 */
#[Entity]
// The moderator queue: cases by status, its "Too fast" / "Too slow" tabs by direction
#[Index(columns: ['status', 'direction'])]
class SuspiciousTimeCase
{
    // Changed only through the named methods below
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, enumType: SuspiciousTimeCaseStatus::class)]
    public SuspiciousTimeCaseStatus $status = SuspiciousTimeCaseStatus::Pending;

    // Much faster or much slower than expected - set by the classifier, a time is never both. Null for a time flagged
    // outside the app that the scan never raised
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, nullable: true, enumType: SuspicionDirection::class)]
    public null|SuspicionDirection $direction = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, nullable: true, enumType: SuspiciousTimeTier::class)]
    public null|SuspiciousTimeTier $tier = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::FLOAT, nullable: true)]
    public null|float $score = null;

    /**
     * What the scan found: [{code, params}] (SuspiciousTimeReason) - the trigger first, then the explanations
     *
     * @var list<array{code: string, params: array<string, int|float|string|bool|null>}>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSONB, options: ['default' => '[]'])]
    public array $reasons = [];

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $expectedSeconds = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, nullable: true, enumType: ExpectedTimeSource::class)]
    public null|ExpectedTimeSource $expectedSource = null;

    // SuspiciousTimeClassifier::VERSION of the last check - null for a time flagged outside the app and never raised
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::SMALLINT, nullable: true)]
    public null|int $detectorVersion = null;

    // The scan's last look at the time while the case was open
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $lastCheckedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $decidedAt = null;

    // The moderator (a player id, no FK) - null for decisions made outside the app or by the app itself
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $decidedById = null;

    /**
     * The reasons the moderator chose for the player when marking - [{code, params}]
     *
     * @var list<array{code: string, params: array<string, int|float|string|bool|null>}>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSONB, options: ['default' => '[]'])]
    public array $reasonsShown = [];

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $moderatorNote = null;

    // The current mark - every notice belongs to one (case, person, marked_at): a new mark is told again
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $markedAt = null;

    // A marked time whose entry changed - the player's edit, or a change outside the edit form the scan found - and
    // that still needs a person ("Player replied" tab)
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $playerEditedAt = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[OneToOne]
        #[JoinColumn(name: 'time_id', nullable: false, onDelete: 'CASCADE')]
        public PuzzleSolvingTime $time,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::STRING, enumType: SuspiciousTimeCaseOrigin::class)]
        public SuspiciousTimeCaseOrigin $origin,
        // The entry the case is about (SuspicionFingerprint) - the last one checked or decided
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 32)]
        public string $fingerprint,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $detectedAt,
    ) {
    }

    /**
     * The scan raised the time - a pending case for a moderator.
     */
    public static function detected(
        UuidInterface $id,
        PuzzleSolvingTime $time,
        SuspicionAssessment $assessment,
        string $fingerprint,
        int $detectorVersion,
        DateTimeImmutable $now,
    ): self {
        $case = new self($id, $time, SuspiciousTimeCaseOrigin::Detector, $fingerprint, $now);
        $case->applyDetection($assessment, $fingerprint, $detectorVersion, $now);

        return $case;
    }

    /**
     * puzzle_solving_time.suspicious was set by SQL on a time without a case - marked, with no reasons and nobody
     * who decided (docs/features/suspicious-time-review.md, "The notice run").
     */
    public static function flaggedOutsideTheApp(UuidInterface $id, PuzzleSolvingTime $time, string $fingerprint, DateTimeImmutable $now): self
    {
        $case = new self($id, $time, SuspiciousTimeCaseOrigin::Manual, $fingerprint, $now);
        $case->markedOutsideTheApp($fingerprint, $now);

        return $case;
    }

    public function isPending(): bool
    {
        return $this->status === SuspiciousTimeCaseStatus::Pending;
    }

    public function isMarked(): bool
    {
        return $this->status === SuspiciousTimeCaseStatus::Marked;
    }

    /**
     * A person decided about exactly this entry - the scan leaves it alone, whatever the detector version.
     */
    public function isDecidedFor(string $fingerprint): bool
    {
        return in_array($this->status, SuspiciousTimeCaseStatus::decided(), true) && $this->fingerprint === $fingerprint;
    }

    /**
     * The scan checked the time again while the case waits for a moderator and still raises it: what it found now.
     */
    public function refreshDetection(SuspicionAssessment $assessment, string $fingerprint, int $detectorVersion, DateTimeImmutable $now): void
    {
        if ($this->status !== SuspiciousTimeCaseStatus::Pending) {
            return;
        }

        $this->applyDetection($assessment, $fingerprint, $detectorVersion, $now);
    }

    /**
     * The scan raises the time again after the case had ended - gone, corrected, or trusted for another entry (trust
     * belongs to the entry it was given to). Pending again, nothing of the earlier decision carries over.
     */
    public function reopen(SuspicionAssessment $assessment, string $fingerprint, int $detectorVersion, DateTimeImmutable $now): void
    {
        if ($this->status === SuspiciousTimeCaseStatus::Pending || $this->status === SuspiciousTimeCaseStatus::Marked || $this->isDecidedFor($fingerprint)) {
            return;
        }

        $this->status = SuspiciousTimeCaseStatus::Pending;
        $this->origin = SuspiciousTimeCaseOrigin::Detector;
        $this->detectedAt = $now;
        $this->decidedAt = null;
        $this->decidedById = null;
        $this->reasonsShown = [];
        $this->moderatorNote = null;
        $this->markedAt = null;
        $this->playerEditedAt = null;
        $this->applyDetection($assessment, $fingerprint, $detectorVersion, $now);
    }

    /**
     * No longer raised (edited, moved, a piece count fixed, no longer solo) before anybody decided.
     */
    public function markGone(DateTimeImmutable $now): void
    {
        if ($this->status !== SuspiciousTimeCaseStatus::Pending) {
            return;
        }

        $this->status = SuspiciousTimeCaseStatus::Gone;
        $this->lastCheckedAt = $now;
    }

    /**
     * A moderator marked the time (the caller sets the flag: PuzzleSolvingTime::markSuspicious()) and chose the
     * reasons the player reads. A new mark - its notices are new too.
     *
     * @param list<SuspiciousTimeReason> $reasonsShown
     */
    public function mark(array $reasonsShown, null|string $note, null|UuidInterface $decidedById, string $fingerprint, DateTimeImmutable $now): void
    {
        $now = self::wholeSeconds($now);

        $this->status = SuspiciousTimeCaseStatus::Marked;
        $this->reasonsShown = SuspiciousTimeReason::listToArray($reasonsShown);
        $this->moderatorNote = $note !== null && trim($note) !== '' ? trim($note) : null;
        $this->decidedById = $decidedById;
        $this->decidedAt = $now;
        $this->markedAt = $now;
        $this->playerEditedAt = null;
        $this->fingerprint = $fingerprint;
    }

    /**
     * The flag appeared without the queue (SQL): a mark nobody chose reasons for.
     */
    public function markedOutsideTheApp(string $fingerprint, DateTimeImmutable $now): void
    {
        $this->mark([], null, null, $fingerprint, $now);
    }

    /**
     * "Not suspicious" or "Unmark" (the caller clears the flag: PuzzleSolvingTime::clearSuspicion()), also an unmark
     * by SQL. This entry is never raised again; an edit that changes its fingerprint lapses the trust.
     */
    public function trust(null|UuidInterface $decidedById, string $fingerprint, DateTimeImmutable $now): void
    {
        $this->status = SuspiciousTimeCaseStatus::Trusted;
        $this->decidedById = $decidedById;
        $this->decidedAt = $now;
        $this->playerEditedAt = null;
        $this->fingerprint = $fingerprint;
    }

    /**
     * The player fixed a marked time, the corrected entry passes and the mark rested on detector reasons - unmarked
     * automatically (the caller clears the flag).
     */
    public function markCorrected(string $fingerprint, DateTimeImmutable $now): void
    {
        if ($this->status !== SuspiciousTimeCaseStatus::Marked) {
            return;
        }

        $this->status = SuspiciousTimeCaseStatus::Corrected;
        $this->decidedById = null;
        $this->decidedAt = $now;
        $this->playerEditedAt = null;
        $this->fingerprint = $fingerprint;
    }

    /**
     * The entry of a marked time changed - the player edited it, or the scan found it changed outside the edit form -
     * and it still needs a person (it still looks off or could not be judged, or the mark was manual or had no
     * reasons): it stays marked and goes back to the queue. With the re-check's assessment the card shows what the
     * detector says about the new entry.
     */
    public function playerEdited(null|SuspicionAssessment $assessment, string $fingerprint, int $detectorVersion, DateTimeImmutable $now): void
    {
        $this->playerEditedAt = $now;
        $this->fingerprint = $fingerprint;

        if ($assessment !== null && $assessment->isRaised()) {
            $this->applyDetection($assessment, $fingerprint, $detectorVersion, $now);
        }
    }

    /**
     * A moderator looked at the player's reply or edit and keeps the mark ("Keep it marked" in the queue): the time
     * stays marked, the "Player replied" tab lets go of it. Bound to the entry the moderator saw. The mark itself -
     * marked_at, the reasons shown - stays, so no new notice goes out; the answer reaches the player on the notice.
     */
    public function keptAfterReply(string $fingerprint): void
    {
        if ($this->status !== SuspiciousTimeCaseStatus::Marked) {
            return;
        }

        $this->playerEditedAt = null;
        $this->fingerprint = $fingerprint;
    }

    /**
     * @return list<SuspiciousTimeReason>
     */
    public function reasons(): array
    {
        return SuspiciousTimeReason::listFromArray($this->reasons);
    }

    /**
     * @return list<SuspiciousTimeReason>
     */
    public function reasonsShown(): array
    {
        return SuspiciousTimeReason::listFromArray($this->reasonsShown);
    }

    private function applyDetection(SuspicionAssessment $assessment, string $fingerprint, int $detectorVersion, DateTimeImmutable $now): void
    {
        $this->direction = $assessment->direction();
        $this->tier = $assessment->tier;
        $this->score = $assessment->score;
        $this->reasons = SuspiciousTimeReason::listToArray($assessment->reasons);
        $this->expectedSeconds = $assessment->expectedSeconds;
        $this->expectedSource = $assessment->expectedSource;
        $this->detectorVersion = $detectorVersion;
        $this->fingerprint = $fingerprint;
        $this->lastCheckedAt = $now;
    }

    /**
     * Stored to the second: a notice is matched to its mark by marked_at, in PHP and in SQL alike.
     */
    private static function wholeSeconds(DateTimeImmutable $moment): DateTimeImmutable
    {
        return $moment->setTimestamp($moment->getTimestamp());
    }
}
