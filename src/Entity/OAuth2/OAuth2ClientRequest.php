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
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Value\OAuth2ApplicationType;
use SpeedPuzzling\Web\Value\OAuth2ClientRequestStatus;

#[Entity]
#[Index(name: 'idx_oauth2_client_request_player', columns: ['player_id'])]
#[Index(name: 'idx_oauth2_client_request_status', columns: ['status'])]
class OAuth2ClientRequest
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(enumType: OAuth2ClientRequestStatus::class)]
    public OAuth2ClientRequestStatus $status = OAuth2ClientRequestStatus::Pending;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|DateTimeImmutable $reviewedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne]
    #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public null|Player $reviewedBy = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $rejectionReason = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $clientIdentifier = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $credentialClaimToken = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|DateTimeImmutable $credentialClaimExpiresAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column]
    public bool $credentialsClaimed = false;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $clientSecret = null;

    /**
     * @param array<string> $requestedScopes
     * @param array<string> $redirectUris
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
        #[Column(length: 100)]
        #[Immutable]
        public string $clientName,
        #[Column(type: Types::TEXT)]
        #[Immutable]
        public string $clientDescription,
        #[Column(type: Types::TEXT)]
        #[Immutable]
        public string $purpose,
        #[Column(enumType: OAuth2ApplicationType::class)]
        #[Immutable]
        public OAuth2ApplicationType $applicationType,
        #[Column(type: Types::JSON)]
        #[Immutable]
        public array $requestedScopes,
        #[Column(type: Types::JSON)]
        #[Immutable]
        public array $redirectUris,
        #[Column]
        #[Immutable]
        public DateTimeImmutable $fairUsePolicyAcceptedAt,
        #[Column]
        #[Immutable]
        public DateTimeImmutable $createdAt,
        #[Column(nullable: true)]
        #[Immutable]
        public null|string $logoPath = null,
    ) {
    }

    public function approve(Player $admin, string $clientIdentifier, null|string $clientSecret, string $claimToken): void
    {
        $this->status = OAuth2ClientRequestStatus::Approved;
        $this->reviewedAt = new DateTimeImmutable();
        $this->reviewedBy = $admin;
        $this->clientIdentifier = $clientIdentifier;
        $this->clientSecret = $clientSecret;
        $this->credentialClaimToken = hash('sha256', $claimToken);
        $this->credentialClaimExpiresAt = new DateTimeImmutable('+7 days');
    }

    public function reject(Player $admin, string $reason): void
    {
        $this->status = OAuth2ClientRequestStatus::Rejected;
        $this->reviewedAt = new DateTimeImmutable();
        $this->reviewedBy = $admin;
        $this->rejectionReason = $reason;
    }

    public function markCredentialsClaimed(): void
    {
        $this->credentialsClaimed = true;
        $this->clientSecret = null;
    }

    public function resetCredentials(string $newSecret, string $claimToken): void
    {
        $this->clientSecret = $newSecret;
        $this->credentialClaimToken = hash('sha256', $claimToken);
        $this->credentialClaimExpiresAt = new DateTimeImmutable('+7 days');
        $this->credentialsClaimed = false;
    }
}
