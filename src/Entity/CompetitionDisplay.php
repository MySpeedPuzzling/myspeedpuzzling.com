<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use InvalidArgumentException;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\DisplayFollowMode;
use SpeedPuzzling\Web\Value\DisplayKind;
use SpeedPuzzling\Web\Value\OnlineToken;

/**
 * A timer screen or stream overlay of one event (docs/features/competitions-management/toolkit/D-displays-overlays.md
 * §3.1): opened by its token URL without signing in - the token is the capability, regenerating it kills the old URL
 * at once. The design (schema v1, §4) is validated by DisplayDesignSanitizer (D2) on every save. Spotlight and message
 * of D's live control are deferred to v2 (R-review-and-plan.md §3) and have no columns yet.
 */
#[Entity]
#[Index(columns: ['competition_id'])]
class CompetitionDisplay
{
    public const int MAX_PER_COMPETITION = 20;
    public const int NAME_MAX_LENGTH = 100;
    public const array LOCALES = ['en', 'cs', 'de', 'es', 'fr', 'ja'];

    /**
     * @param array<string, mixed> $design
     * @param null|list<string> $followRoundIds
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Competition $competition,
        #[Immutable]
        #[Column(type: Types::STRING, length: 16, enumType: DisplayKind::class)]
        public DisplayKind $kind,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: self::NAME_MAX_LENGTH)]
        public string $name,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: OnlineToken::LENGTH, unique: true)]
        public string $token,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 5)]
        public string $locale,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSONB)]
        public array $design,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Player $createdBy,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $updatedAt,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(options: ['default' => 1])]
        public int $designVersion = 1,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::STRING, length: 16, enumType: DisplayFollowMode::class)]
        public DisplayFollowMode $followMode = DisplayFollowMode::Current,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|CompetitionRound $pinnedRound = null,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSONB, nullable: true)]
        public null|array $followRoundIds = null,
        // Derived from the design on save (D2): any element with source "live" / any results element at all
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(options: ['default' => false])]
        public bool $usesLiveResults = false,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(options: ['default' => false])]
        public bool $usesResults = false,
    ) {
    }

    /**
     * The organiser's label, trimmed and cut to NAME_MAX_LENGTH (the forms refuse longer ones).
     *
     * @throws InvalidArgumentException for a name that is empty after trimming
     */
    public static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));

        if ($name === '') {
            throw new InvalidArgumentException('A display needs a name.');
        }

        return mb_substr($name, 0, self::NAME_MAX_LENGTH);
    }

    /**
     * @throws InvalidArgumentException for a language the site has no texts in
     */
    public static function checkLocale(string $locale): string
    {
        if (!in_array($locale, self::LOCALES, true)) {
            throw new InvalidArgumentException('Unknown display language: ' . $locale);
        }

        return $locale;
    }

    public function rename(string $name, DateTimeImmutable $at): void
    {
        if ($this->name === $name) {
            return;
        }

        $this->name = $name;
        $this->updatedAt = $at;
    }

    public function changeLocale(string $locale, DateTimeImmutable $at): void
    {
        if ($this->locale === $locale) {
            return;
        }

        $this->locale = $locale;
        $this->updatedAt = $at;
    }

    /**
     * @param null|list<string> $roundIds a subset of the event's rounds for "current" (null = every round)
     */
    public function follow(DisplayFollowMode $mode, null|CompetitionRound $pinnedRound, null|array $roundIds, DateTimeImmutable $at): void
    {
        $this->followMode = $mode;
        $this->pinnedRound = $mode === DisplayFollowMode::Pinned ? $pinnedRound : null;
        $this->followRoundIds = $mode === DisplayFollowMode::Current ? $roundIds : null;
        $this->updatedAt = $at;
    }

    /**
     * A copy in the same event (D §3.3): same design, language and round to follow, a new link of its own. The design
     * version starts again - the copy is a new display for every open renderer.
     */
    public function duplicateAs(UuidInterface $id, string $name, string $token, null|Player $by, DateTimeImmutable $at): self
    {
        return new self(
            id: $id,
            competition: $this->competition,
            kind: $this->kind,
            name: $name,
            token: $token,
            locale: $this->locale,
            design: $this->design,
            createdAt: $at,
            createdBy: $by,
            updatedAt: $at,
            followMode: $this->followMode,
            pinnedRound: $this->pinnedRound,
            followRoundIds: $this->followRoundIds,
            usesLiveResults: $this->usesLiveResults,
            usesResults: $this->usesResults,
        );
    }

    /**
     * The old URL stops working at once - whoever had it sees nothing any more.
     */
    public function regenerateToken(string $token, DateTimeImmutable $at): void
    {
        $this->token = $token;
        $this->updatedAt = $at;
    }
}
