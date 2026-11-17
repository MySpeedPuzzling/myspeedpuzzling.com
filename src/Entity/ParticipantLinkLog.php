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
use SpeedPuzzling\Web\Value\ParticipantLinkAction;
use SpeedPuzzling\Web\Value\ParticipantLinkMethod;

/**
 * Append-only record of every link and unlink of a competition participant to a MySpeedPuzzling account
 * (docs/features/competitions-management/participant-linking.md §3.4) - written by ParticipantLinks, and by
 * ParticipantLinksOfDeletedAccount in SQL. No foreign keys, ids and names copied - like result_claim_log it outlives the participant,
 * the player and the event. A deleted account's name is erased to '' (§11), its id stays.
 */
#[Entity]
#[Index(columns: ['competition_id', 'participant_id'])]
#[Index(columns: ['player_id', 'action'])]
class ParticipantLinkLog
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $competitionId,
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $participantId,
        // The organiser's name of the person at the time
        #[Immutable]
        #[Column]
        public string $participantName,
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $playerId,
        // The profile's name at the time - '' once the account is deleted
        #[Immutable]
        #[Column]
        public string $playerName,
        #[Immutable]
        #[Column(type: Types::STRING, length: 30, enumType: ParticipantLinkAction::class)]
        public ParticipantLinkAction $action,
        // How the link was made - null for every action that is no link
        #[Immutable]
        #[Column(type: Types::STRING, length: 20, nullable: true, enumType: ParticipantLinkMethod::class)]
        public null|ParticipantLinkMethod $method,
        // Who did it - the player themselves, an organiser; null for the system
        #[Immutable]
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $byPlayerId,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $at,
    ) {
    }
}
