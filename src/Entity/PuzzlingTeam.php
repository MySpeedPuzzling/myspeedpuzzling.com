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
use SpeedPuzzling\Web\Events\PuzzlingTeamRenamed;

/**
 * A pair or a team: the exact set of people who solved together (docs/features/pairs-and-teams/README.md).
 * Same people = same row, whatever their order; other people = another row. The members never change -
 * editing who took part in a time moves that time to another team.
 */
#[Entity]
class PuzzlingTeam implements EntityWithEvents
{
    use HasEvents;

    public const int NAME_MAX_LENGTH = 50;

    #[ManyToOne]
    #[JoinColumn(onDelete: 'SET NULL')]
    public null|Player $namedBy = null;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $namedAt = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        // sha1 of the sorted member keys, see TeamComposition
        #[Column(type: Types::STRING, length: 40, unique: true, options: ['fixed' => true])]
        public string $compositionKey,
        #[Column(type: Types::SMALLINT)]
        public int $size,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
        #[Column(type: Types::STRING, length: self::NAME_MAX_LENGTH, nullable: true)]
        public null|string $name = null,
        // Set when the team was put together ahead on the "Pairs & teams" page, before any time together
        #[ManyToOne]
        #[JoinColumn(onDelete: 'SET NULL')]
        public null|Player $preparedBy = null,
    ) {
    }

    public function isPair(): bool
    {
        return $this->size === 2;
    }

    /**
     * Free text typed by a player: single spaces, at most NAME_MAX_LENGTH characters, empty means no name.
     */
    public static function cleanName(null|string $name): null|string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name ?? ''));

        return $name === '' ? null : mb_substr($name, 0, self::NAME_MAX_LENGTH);
    }

    /**
     * The add/edit time form may name a pair/team, never rename it - that is what the
     * "Pairs & teams" page is for, where the change is a deliberate act of its own.
     */
    public function nameIfUnnamed(Player $by, null|string $name, DateTimeImmutable $at): void
    {
        $name = self::cleanName($name);

        if ($this->name !== null || $name === null) {
            return;
        }

        $this->rename($by, $name, $at);
    }

    public function rename(Player $by, null|string $name, DateTimeImmutable $at): void
    {
        $name = self::cleanName($name);

        if ($name === $this->name) {
            return;
        }

        $this->name = $name;
        $this->namedBy = $by;
        $this->namedAt = $at;

        $this->recordThat(new PuzzlingTeamRenamed($this->id, $by->id));
    }
}
