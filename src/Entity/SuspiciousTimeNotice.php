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
use SpeedPuzzling\Web\Value\SuspiciousTimeNoticeVia;
use SpeedPuzzling\Web\Value\SuspiciousTimeReplyAnswer;
use SpeedPuzzling\Web\Value\SuspiciousTimeResponse;

/**
 * One person told about one mark of a time (docs/features/suspicious-time-review.md, "Telling the player"): the
 * tracker and every registered member of a pair/team get their own. A mark that simply stays is never announced
 * again; an unmark followed by a new mark is a new mark (another marked_at) and a new notice.
 *
 * The player's reaction (response) and a moderator's answer to "The time is correct" live here too, as do the ids of
 * the "Your results" e-mails that carried the mark and the answer (result_review_contact, plain ids like its own
 * case_ids).
 */
#[Entity]
#[UniqueConstraint(columns: ['case_id', 'player_id', 'marked_at'])]
class SuspiciousTimeNotice
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, nullable: true, enumType: SuspiciousTimeResponse::class)]
    public null|SuspiciousTimeResponse $response = null;

    // The player's message with "The time is correct" (≤ 500 characters)
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $responseText = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $respondedAt = null;

    // The moderator's answer to a reply
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, nullable: true, enumType: SuspiciousTimeReplyAnswer::class)]
    public null|SuspiciousTimeReplyAnswer $answer = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $answerNote = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $answeredAt = null;

    // The "Your results" e-mail that carried the mark
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $contactId = null;

    // The "Your results" e-mail that carried the moderator's answer
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $answerContactId = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(name: 'case_id', nullable: false, onDelete: 'CASCADE')]
        public SuspiciousTimeCase $case,
        // The person told - the tracker or a registered member of the pair/team
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        // = the case's marked_at when the notice was made
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $markedAt,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $notifiedAt,
        // manual_email: the player was told by hand before the notice run existed (--existing-marks-told-by-hand)
        #[Immutable]
        #[Column(type: Types::STRING, enumType: SuspiciousTimeNoticeVia::class)]
        public SuspiciousTimeNoticeVia $via,
    ) {
    }

    public function isAbout(SuspiciousTimeCase $case): bool
    {
        return $case->markedAt !== null && $this->markedAt->getTimestamp() === $case->markedAt->getTimestamp();
    }

    /**
     * What the player did: fixed it, "The time is correct" (+ an optional message for the moderators) or "Leave it
     * as it is". The time is correct goes to the moderators once per mark - a reply already sent stays as it was.
     *
     * Fixing the time or saying it is correct asks the moderators again: an answer they gave to an earlier edit is
     * about what came before and makes way for the next one.
     */
    public function respond(SuspiciousTimeResponse $response, null|string $text, DateTimeImmutable $now): void
    {
        if ($this->response === SuspiciousTimeResponse::SaysCorrect) {
            return;
        }

        $text = $text !== null ? trim($text) : null;

        $this->response = $response;
        $this->responseText = $response === SuspiciousTimeResponse::SaysCorrect && $text !== '' ? $text : null;
        $this->respondedAt = $now;

        if ($response === SuspiciousTimeResponse::Fixed || $response === SuspiciousTimeResponse::SaysCorrect) {
            $this->answer = null;
            $this->answerNote = null;
            $this->answeredAt = null;
            $this->answerContactId = null;
        }
    }

    /**
     * The player fixed the time or said it is correct - something a moderator answers ("Keep it marked" / "Looks
     * fine"). Leaving it as it is asks nothing.
     */
    public function awaitsAnswer(): bool
    {
        return $this->answer === null
            && ($this->response === SuspiciousTimeResponse::SaysCorrect || $this->response === SuspiciousTimeResponse::Fixed);
    }

    /**
     * A moderator's answer to the player's reply or fix: trusted ("Your time counts again") or kept + a note. A new
     * answer - a later "Looks fine" over an earlier "Stays marked" too - is told again, once.
     */
    public function answer(SuspiciousTimeReplyAnswer $answer, null|string $note, DateTimeImmutable $now): void
    {
        $note = $note !== null ? trim($note) : null;

        $this->answer = $answer;
        $this->answerNote = $note !== '' ? $note : null;
        $this->answeredAt = $now;
        $this->answerContactId = null;
    }

    /**
     * The mark went out in this "Your results" e-mail - once.
     */
    public function sentInContact(UuidInterface $contactId): void
    {
        if ($this->contactId !== null) {
            return;
        }

        $this->contactId = $contactId;
    }

    /**
     * The moderator's answer went out in this "Your results" e-mail - once.
     */
    public function answerSentInContact(UuidInterface $contactId): void
    {
        if ($this->answerContactId !== null) {
            return;
        }

        $this->answerContactId = $contactId;
    }
}
