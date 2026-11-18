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
use InvalidArgumentException;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * One division of an event (docs/features/competitions-management/shared-puzzles-and-divisions.md §3, D1) - "Senior
 * 55+", "Junior U16". The list is the event's; a round uses a subset of it (CompetitionRoundDivision) and the division
 * sits on the round entry (CompetitionParticipantRound::$division of a solo entry, CompetitionTeam::$division of a
 * pair/team). An event without divisions has no rows - every page and answer stays as it was.
 *
 * Written only by SetCompetitionDivisions (names unique per event case-insensitively, at most MAX_PER_COMPETITION).
 */
#[Entity]
class CompetitionDivision
{
    public const int MAX_PER_COMPETITION = 12;
    public const int NAME_MAX_LENGTH = 60;
    public const int SHORT_NAME_MAX_LENGTH = 12;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Competition $competition,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: self::NAME_MAX_LENGTH)]
        public string $name,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
        // A short label for tight places (the desk's rank cell, tags) - null = the name is used
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: self::SHORT_NAME_MAX_LENGTH, nullable: true)]
        public null|string $shortName = null,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::SMALLINT, options: ['default' => 0])]
        public int $position = 0,
    ) {
        $this->name = self::cleanName($name);
        $this->shortName = self::cleanShortName($shortName);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function change(string $name, null|string $shortName, int $position): void
    {
        $this->name = self::cleanName($name);
        $this->shortName = self::cleanShortName($shortName);
        $this->position = $position;
    }

    /**
     * Single spaces; empty or too long is refused.
     *
     * @throws InvalidArgumentException
     */
    public static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new InvalidArgumentException('Invalid division name');
        }

        return $name;
    }

    /**
     * Single spaces; empty means none, too long is refused.
     *
     * @throws InvalidArgumentException
     */
    public static function cleanShortName(null|string $shortName): null|string
    {
        $shortName = trim((string) preg_replace('/\s+/u', ' ', $shortName ?? ''));

        if (mb_strlen($shortName) > self::SHORT_NAME_MAX_LENGTH) {
            throw new InvalidArgumentException('Invalid division short name');
        }

        return $shortName === '' ? null : $shortName;
    }
}
