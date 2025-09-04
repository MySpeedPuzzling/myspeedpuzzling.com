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
use SpeedPuzzling\Web\Events\PuzzleBorrowed;
use SpeedPuzzling\Web\Events\PuzzleReturned;

#[Entity]
class PuzzleBorrowing implements EntityWithEvents
{
    use HasEvents;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $returnedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $nonRegisteredPersonName = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Puzzle $puzzle,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Player $owner,
        #[Immutable]
        #[ManyToOne]
        public null|Player $borrower,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $borrowedAt,
        #[Immutable]
        #[Column(type: Types::BOOLEAN)]
        public bool $borrowedFrom = false, // false = borrowed to someone, true = borrowed from someone
    ) {
        if ($this->borrower !== null) {
            if ($this->borrowedFrom) {
                // Owner borrowed from registered borrower
                $this->recordThat(new PuzzleBorrowed(
                    $this->puzzle->id,
                    $this->borrower->id,
                    $this->owner->id,
                    null,
                    true
                ));
            } else {
                // Owner lent to registered borrower
                $this->recordThat(new PuzzleBorrowed(
                    $this->puzzle->id,
                    $this->owner->id,
                    $this->borrower->id,
                    null,
                    false
                ));
            }
        }
    }

    public function setNonRegisteredPersonName(string $name): void
    {
        $this->nonRegisteredPersonName = $name;
    }

    public function returnPuzzle(Player $initiator): void
    {
        $this->returnedAt = new DateTimeImmutable();

        if ($this->borrower !== null) {
            $this->recordThat(new PuzzleReturned(
                $this->puzzle->id,
                $this->owner->id,
                $this->borrower->id,
                $initiator->id
            ));
        } else {
            $this->recordThat(new PuzzleReturned(
                $this->puzzle->id,
                $this->owner->id,
                null,
                $initiator->id
            ));
        }
    }

    public function isActive(): bool
    {
        return $this->returnedAt === null;
    }
}
