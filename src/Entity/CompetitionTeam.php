<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionTeamNameTooLong;

#[Entity]
class CompetitionTeam
{
    public const int NAME_MAX_LENGTH = 255;

    /**
     * @throws CompetitionTeamNameTooLong
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public CompetitionRound $round,
        #[Column(length: self::NAME_MAX_LENGTH, nullable: true)]
        public null|string $name = null,
    ) {
        $this->name = self::checkedName($name);
    }

    /**
     * Free text typed by an organizer: single spaces, empty means no name.
     */
    public static function cleanName(null|string $name): null|string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name ?? ''));

        return $name === '' ? null : $name;
    }

    /**
     * @throws CompetitionTeamNameTooLong
     */
    public function rename(null|string $name): void
    {
        $this->name = self::checkedName($name);
    }

    /**
     * @throws CompetitionTeamNameTooLong
     */
    private static function checkedName(null|string $name): null|string
    {
        $name = self::cleanName($name);

        if ($name !== null && mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new CompetitionTeamNameTooLong();
        }

        return $name;
    }
}
