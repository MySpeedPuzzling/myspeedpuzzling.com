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

/**
 * One row = this player was told about their official result in this round ("Your official result is out",
 * NotifyWhenOfficialRoundResultsPublished - docs/features/competitions-management/official-results.md). The unique
 * constraint is the whole "never twice" guarantee: the row is claimed with INSERT .. ON CONFLICT DO NOTHING
 * (OfficialResultNoticeRepository::claim()) in the transaction that writes the notification, so two notification runs
 * of one round (a republish, a late result, an approval) meet on the index and only one of them tells the player.
 * Rows are written by SQL and only mapped here for the schema; they go with the player or the round.
 */
#[Entity]
#[UniqueConstraint(name: 'official_result_notice_unique', columns: ['player_id', 'round_id'])]
class OfficialResultNotice
{
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
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public CompetitionRound $round,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $notifiedAt,
    ) {
    }
}
