<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\EventResultsImport\ResultClaimAction;

/**
 * Append-only record of every claim of a spot of an imported event result and what became of it
 * (docs/features/event-results-import/README.md "Gates"). No foreign keys, ids and the name copied - like
 * puzzle_moderation_decision it outlives the result, the player and the event, so a wrong claim stays answerable.
 */
#[Entity]
#[Index(columns: ['solving_time_id'])]
#[Index(columns: ['player_id', 'action'])]
class ResultClaimLog
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $solvingTimeId,
        #[Immutable]
        #[Column(length: 40)]
        public string $slotKey,
        // Whose spot it is
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $playerId,
        #[Immutable]
        #[Column]
        public string $playerName,
        #[Immutable]
        #[Column(type: Types::STRING, enumType: ResultClaimAction::class)]
        public ResultClaimAction $action,
        // Who did it - the player themselves, another claimer, the organizer's team, an admin; null for the system
        #[Immutable]
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $byPlayerId,
        #[Immutable]
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $reason,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $at,
    ) {
    }
}
