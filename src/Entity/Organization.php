<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\OrganizationKind;
use SpeedPuzzling\Web\Value\SocialLinks;

/**
 * Who runs events - an association, a club, a shop or brand, a venue (docs/features/organizations/README.md): an
 * optional level above series and one-time events. Its team (creator + maintainers) has the creator's rights on
 * everything under it (GetCompetitionPermissions). Approved like a series; a draft hides only its own page, directory
 * entry and "Organized by" links - never its series or events (IsOrganizationPubliclyVisible).
 */
#[Entity]
class Organization
{
    /**
     * The other links (Instagram, Discord, …) as plain URLs - read them as socialLinks(), change them with edit()
     *
     * @var list<string>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSONB, options: ['default' => '[]'])]
    public array $socialLinks = [];

    /**
     * @param Collection<int, Player> $maintainers
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Column]
        public string $name,
        #[Column(unique: true)]
        public string $slug,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
        #[Column(nullable: true)]
        public null|string $shortName = null,
        #[Column(nullable: true)]
        public null|string $logo = null,
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $about = null,
        #[Column(nullable: true)]
        public null|string $website = null,
        SocialLinks $links = new SocialLinks([]),
        #[Column(nullable: true)]
        public null|string $countryCode = null,
        #[Column(nullable: true)]
        public null|string $region = null,
        #[Column(type: Types::STRING, nullable: true, enumType: OrganizationKind::class)]
        public null|OrganizationKind $kind = null,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(options: ['default' => false])]
        public bool $isDraft = false,
        #[Immutable]
        #[ManyToOne]
        public null|Player $addedByPlayer = null,
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $approvedAt = null,
        #[ManyToOne]
        public null|Player $approvedByPlayer = null,
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $rejectedAt = null,
        #[ManyToOne]
        public null|Player $rejectedByPlayer = null,
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $rejectionReason = null,
        /**
         * @var Collection<int, Player>
         */
        #[ManyToMany(targetEntity: Player::class)]
        #[JoinTable(name: 'organization_maintainer')]
        public Collection $maintainers = new ArrayCollection(),
    ) {
        $this->socialLinks = $links->urls;
        $this->countryCode = self::normalizeCountryCode($countryCode);
    }

    public function approve(Player $approvedBy, DateTimeImmutable $approvedAt): void
    {
        $this->approvedAt = $approvedAt;
        $this->approvedByPlayer = $approvedBy;
    }

    public function reject(Player $rejectedBy, DateTimeImmutable $rejectedAt, string $reason): void
    {
        $this->rejectedAt = $rejectedAt;
        $this->rejectedByPlayer = $rejectedBy;
        $this->rejectionReason = $reason;
    }

    public function isApproved(): bool
    {
        return $this->approvedAt !== null;
    }

    public function isRejected(): bool
    {
        return $this->rejectedAt !== null;
    }

    public function publish(): void
    {
        $this->isDraft = false;
    }

    public function unpublish(): void
    {
        $this->isDraft = true;
    }

    /**
     * The PHP mirror of IsOrganizationPubliclyVisible::SQL_CONDITION (VisibilityParityTest keeps them equal)
     */
    public function isPubliclyVisible(): bool
    {
        return $this->approvedAt !== null && $this->rejectedAt === null && $this->isDraft === false;
    }

    /**
     * Its creator or one of its maintainers
     */
    public function isOnTeam(Player $player): bool
    {
        if ($this->addedByPlayer !== null && $this->addedByPlayer->id->equals($player->id)) {
            return true;
        }

        return $this->maintainers->exists(static fn (int $key, Player $maintainer): bool => $maintainer->id->equals($player->id));
    }

    public function socialLinks(): SocialLinks
    {
        return new SocialLinks($this->socialLinks);
    }

    public function edit(
        string $name,
        string $slug,
        null|string $shortName,
        null|string $logo,
        null|string $about,
        null|string $website,
        SocialLinks $socialLinks,
        null|string $countryCode,
        null|string $region,
        null|OrganizationKind $kind,
    ): void {
        $this->name = $name;
        $this->slug = $slug;
        $this->shortName = $shortName;
        $this->logo = $logo;
        $this->about = $about;
        $this->website = $website;
        $this->socialLinks = $socialLinks->urls;
        $this->countryCode = self::normalizeCountryCode($countryCode);
        $this->region = $region;
        $this->kind = $kind;
    }

    private static function normalizeCountryCode(null|string $countryCode): null|string
    {
        return $countryCode !== null ? strtolower($countryCode) : null;
    }
}
