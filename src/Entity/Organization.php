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
    // The team besides its creator (docs/features/organizations/README.md "Data model")
    public const int MAX_MAINTAINERS = 10;
    // The limits of the forms and the internal API (P14)
    public const int ABOUT_MAX_LENGTH = 5000;
    public const int REGION_MAX_LENGTH = 120;
    public const int WEBSITE_MAX_LENGTH = 255;

    /**
     * When it entered the approval queue (docs/features/organizations/README.md "Approval"): created published, first
     * published, approved, or created or published by an admin or the internal API. The admins' "submitted" e-mail
     * goes out only while it is null, so going back to draft and publishing again never e-mails them twice. Rows from
     * before the column are null.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $submittedAt = null;

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
        // An approved item is past the queue - publishing it later e-mails nobody
        $this->markSubmitted($approvedAt);
    }

    /**
     * It entered the approval queue (or skipped it) - kept at the first time
     */
    public function markSubmitted(DateTimeImmutable $submittedAt): void
    {
        $this->submittedAt ??= $submittedAt;
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
     * The team's ids, each once, without the creator (the creator is on the team as its creator)
     *
     * @param list<string> $maintainerIds
     * @return list<string>
     */
    public static function teamIds(array $maintainerIds, null|string $creatorId): array
    {
        $team = [];

        foreach ($maintainerIds as $maintainerId) {
            $id = strtolower($maintainerId);

            if ($id !== $creatorId) {
                $team[$id] = $id;
            }
        }

        return array_values($team);
    }

    /**
     * A text copied into "About" from elsewhere (a series' description), cut to its limit at a word - so a later edit
     * that does not touch it never fails on it
     */
    public static function fittedAbout(null|string $text): null|string
    {
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) <= self::ABOUT_MAX_LENGTH) {
            return $text;
        }

        // Room for the ellipsis; back to the last space or line break when there is one near the end
        $cut = mb_substr($text, 0, self::ABOUT_MAX_LENGTH - 1);
        $lastBreak = max((int) mb_strrpos($cut, ' '), (int) mb_strrpos($cut, "\n"));

        if ($lastBreak > self::ABOUT_MAX_LENGTH - 200) {
            $cut = mb_substr($cut, 0, $lastBreak);
        }

        return rtrim($cut) . '…';
    }

    /**
     * A place copied into "Region" from elsewhere (a series' location) - only when it fits, else none
     */
    public static function fittedRegion(null|string $place): null|string
    {
        $place = trim((string) $place);

        return $place !== '' && mb_strlen($place) <= self::REGION_MAX_LENGTH ? $place : null;
    }

    /**
     * A link copied into "Website" from elsewhere (a series' link) - only an http(s) address that fits, else none
     */
    public static function fittedWebsite(null|string $link): null|string
    {
        $link = trim((string) $link);

        return $link !== '' && mb_strlen($link) <= self::WEBSITE_MAX_LENGTH && SocialLinks::isWebAddress($link) ? $link : null;
    }

    /**
     * May the player put a series or an event under it: its team, or an admin (docs/features/organizations/README.md
     * "Permissions")
     */
    public function isManagedBy(Player $player): bool
    {
        return $player->isAdmin || $this->isOnTeam($player);
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
