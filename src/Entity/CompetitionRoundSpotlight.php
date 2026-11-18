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
use SpeedPuzzling\Web\Value\RoundEntryRef;

/**
 * The entry a round's displays put in the spotlight (competition toolkit v2 L2, displays-standings.md §5.1) - one per
 * round, so a hall screen and the stream overlay of the same round agree and parallel halls keep their own. Set by one
 * tap on the round's stopwatch page or the live control page (SetRoundSpotlight, under the event's displays lock -
 * never the participants lock); a table of its own, so `competition_round` and its goldens stay untouched. Goes with
 * its round (also when the round moves to another event).
 */
#[Entity]
class CompetitionRoundSpotlight
{
    public const int ENTRY_REF_MAX_LENGTH = 60;

    public function __construct(
        #[Id]
        #[Immutable]
        #[OneToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public CompetitionRound $round,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: self::ENTRY_REF_MAX_LENGTH)]
        public string $entryRef,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $setAt,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Player $setBy = null,
    ) {
    }

    /**
     * @param RoundEntryRef $entry an entry of the round (SetRoundSpotlightHandler checks it)
     */
    public function spotlight(RoundEntryRef $entry, null|Player $by, DateTimeImmutable $at): void
    {
        $this->entryRef = $entry->toString();
        $this->setBy = $by;
        $this->setAt = $at;
    }
}
