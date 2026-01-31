<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity\OAuth2;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\Player;

#[Entity]
#[Table(name: 'oauth2_user_consent')]
#[Index(name: 'idx_oauth2_user_consent_player_client', columns: ['player_id', 'client_identifier'])]
class OAuth2UserConsent
{
    /**
     * @param array<string> $scopes
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[Immutable]
        public Player $player,
        #[Column]
        #[Immutable]
        public string $clientIdentifier,
        #[Column(type: Types::JSON)]
        public array $scopes,
        #[Column]
        #[Immutable]
        public DateTimeImmutable $consentedAt,
    ) {
    }

    /**
     * @param array<string> $scopes
     */
    public function updateScopes(array $scopes): void
    {
        $this->scopes = $scopes;
    }
}
