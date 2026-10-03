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
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;

/**
 * One subject of a player's comparison line-up (docs/features/player-comparison.md): another player (their solo
 * times), the player themselves, or an exact pair/team. Exactly one of the two subject columns is set - the named
 * constructors are the only way in. Which line-up (Solo / Pairs / Teams) the row belongs to follows from the subject.
 */
#[Entity]
#[UniqueConstraint(columns: ['player_id', 'subject_player_id'])]
#[UniqueConstraint(columns: ['player_id', 'subject_team_id'])]
class ComparisonSubject
{
    private function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        // The owner of the line-up
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'CASCADE')]
        public null|Player $subjectPlayer,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'CASCADE')]
        public null|PuzzlingTeam $subjectTeam,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $addedAt,
    ) {
    }

    public static function ofPlayer(UuidInterface $id, Player $owner, Player $subject, DateTimeImmutable $addedAt): self
    {
        return new self($id, $owner, $subject, null, $addedAt);
    }

    public static function ofTeam(UuidInterface $id, Player $owner, PuzzlingTeam $subject, DateTimeImmutable $addedAt): self
    {
        return new self($id, $owner, null, $subject, $addedAt);
    }

    public function kind(): ComparisonKind
    {
        if ($this->subjectTeam !== null) {
            return ComparisonKind::forTeamSize($this->subjectTeam->size);
        }

        return ComparisonKind::Solo;
    }

    public function ref(): ComparisonSubjectRef
    {
        if ($this->subjectTeam !== null) {
            return ComparisonSubjectRef::team($this->subjectTeam->id->toString());
        }

        assert($this->subjectPlayer !== null);

        return ComparisonSubjectRef::player($this->subjectPlayer->id->toString());
    }

    /**
     * The owner compares themselves - in the Solo line-up only.
     */
    public function isSelf(): bool
    {
        return $this->subjectPlayer !== null && $this->subjectPlayer->id->equals($this->player->id);
    }
}
