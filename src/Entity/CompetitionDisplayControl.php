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

/**
 * The live control of an event's displays (competition toolkit v2 L1, displays-standings.md §5.1): the organiser's
 * message that every display of the event with a `message` element shows ("Break - next round 14:30"), and the last
 * RECENT_MESSAGES ones for one-tap reuse on the live control page. One row per event, created by its first message; a
 * table of its own, so `competition` and its goldens stay untouched. Written by SetCompetitionDisplayMessage only (under
 * the event's displays lock - never the participants lock: a judge's save never waits for a stream operator).
 */
#[Entity]
class CompetitionDisplayControl
{
    public const int MESSAGE_MAX_LENGTH = 200;
    public const int RECENT_MESSAGES = 5;

    // Line breaks, tabs and every other control character become one space - a message is one line on a stream
    private const string CONTROL_CHARACTERS = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}]+/u';

    /**
     * @param list<string> $recentMessages newest first, each once
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[OneToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Competition $competition,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $updatedAt,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: self::MESSAGE_MAX_LENGTH, nullable: true)]
        public null|string $message = null,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $messageSetAt = null,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSONB)]
        public array $recentMessages = [],
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Player $updatedBy = null,
    ) {
    }

    /**
     * A message as the displays show it: one line (line breaks and other control characters become one space),
     * trimmed. The length is the caller's check (MESSAGE_MAX_LENGTH characters - refused, never cut: a cut sentence on a
     * stream says something else).
     */
    public static function cleanMessage(string $text): string
    {
        return trim((string) preg_replace(self::CONTROL_CHARACTERS, ' ', $text));
    }

    /**
     * @param string $text a cleaned, non-empty message of at most MESSAGE_MAX_LENGTH characters (cleanMessage())
     */
    public function setMessage(string $text, null|Player $by, DateTimeImmutable $at): void
    {
        $others = array_values(array_filter($this->recentMessages, static fn (string $recent): bool => $recent !== $text));

        $this->message = $text;
        $this->messageSetAt = $at;
        $this->recentMessages = array_slice([$text, ...$others], 0, self::RECENT_MESSAGES);
        $this->updatedBy = $by;
        $this->updatedAt = $at;
    }

    /**
     * The displays show their `whenEmpty` again; the recent messages stay for the next tap.
     */
    public function clearMessage(null|Player $by, DateTimeImmutable $at): void
    {
        $this->message = null;
        $this->messageSetAt = null;
        $this->updatedBy = $by;
        $this->updatedAt = $at;
    }
}
