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
use SpeedPuzzling\Web\Value\DataImport\ImportPersonChoice;

/**
 * A player's remembered answer for one person's name (docs/features/data-import/people.md "Remembered answers"):
 * the same name in their next file arrives decided - shown as remembered, and changeable. Written only by the
 * importing player's own answer (DecideImportPersonHandler), never by a code that resolved by itself. One per
 * player and key; it goes with the player it names.
 */
#[Entity]
#[UniqueConstraint(columns: ['player_id', 'person_key'])]
class ImportPersonDecision
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 8, enumType: ImportPersonChoice::class)]
    public ImportPersonChoice $choice;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne]
    #[JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public null|Player $linkedPlayer = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $decidedAt;

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
        #[Column(length: 160)]
        public string $personKey,
        ImportPersonChoice $choice,
        null|Player $linkedPlayer,
        DateTimeImmutable $decidedAt,
    ) {
        $this->decide($choice, $linkedPlayer, $decidedAt);
    }

    public function decide(ImportPersonChoice $choice, null|Player $linkedPlayer, DateTimeImmutable $now): void
    {
        if (($choice === ImportPersonChoice::Player) !== ($linkedPlayer !== null)) {
            throw new \LogicException('A remembered person is a player with that player, or a guest / the importing player without one.');
        }

        $this->choice = $choice;
        $this->linkedPlayer = $linkedPlayer;
        $this->decidedAt = $now;
    }
}
