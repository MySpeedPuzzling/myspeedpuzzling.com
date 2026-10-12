<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Results\BadgeResult;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The achievements showcase near the top of a profile (docs/features/xp-levels/launch-2026-10.md, "Profile header",
 * draft A): a member's earned achievements in one sideways-scrolling row, Early Adopter then the highest tier first,
 * with "11 of 16 · 640 AP" linking to the catalogue.
 *
 *  - the player is a member → the showcase for everybody who may see the profile; the owner's unrevealed
 *    achievements are one call-out leading to the reveal page (how many, what they are, one button)
 *  - the player is not a member, viewing their own profile → one quiet line, no lock, no badges
 *  - not a member, anybody else → nothing
 *  - a private profile hidden from the viewer → nothing
 *  - the player hid their achievements ("Hide my achievements", docs/features/opt-out.md) → nothing, owner included
 *  - nothing at all while achievements are not visible to this viewer (XpFeatureGate: launch ready or admin)
 */
#[AsTwigComponent]
final class AchievementsShowcase
{
    public const string MODE_SHOWCASE = 'showcase';
    public const string MODE_TEASER = 'teaser';
    public const string MODE_HIDDEN = 'hidden';

    public null|PlayerProfile $player = null;

    public string $mode = self::MODE_HIDDEN;

    public bool $ownProfile = false;

    /** @var list<BadgeResult> */
    public array $badges = [];

    /** The non-member owner has earned achievements (their Achievement Points, from the profile row - no query) */
    public bool $hasEarned = false;

    public function __construct(
        readonly private GetBadges $getBadges,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
    }

    #[PostMount]
    public function load(): void
    {
        $player = $this->player;

        if ($player === null) {
            return;
        }

        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($this->xpFeatureGate->isVisibleFor($viewer) === false || $player->achievementsOptedOut) {
            return;
        }

        $this->ownProfile = $viewer?->playerId === $player->playerId;

        if ($player->isPrivate && $this->ownProfile === false) {
            return;
        }

        if ($player->activeMembership) {
            $this->badges = self::bestFirst($this->getBadges->forPlayer($player->playerId));
            // A member without any achievement yet: the owner gets the card with the way to the catalogue
            $this->mode = $this->badges !== [] || $this->ownProfile ? self::MODE_SHOWCASE : self::MODE_HIDDEN;

            return;
        }

        if ($this->ownProfile) {
            $this->hasEarned = $player->achievementPoints > 0;
            $this->mode = self::MODE_TEASER;
        }
    }

    /**
     * Counted like the achievements pages: the 16 that can be earned, plus Early Adopter for its holders (it can no
     * longer be earned, so it is only in its holders' total)
     */
    public function totalTypes(): int
    {
        $tiered = count(array_filter(BadgeType::cases(), static fn (BadgeType $type): bool => $type->isTiered()));

        return $tiered + ($this->holdsSupporter() ? 1 : 0);
    }

    public function earnedTypes(): int
    {
        return count(array_filter($this->badges, static fn (BadgeResult $badge): bool => $badge->type->isTiered()))
            + ($this->holdsSupporter() ? 1 : 0);
    }

    private function holdsSupporter(): bool
    {
        return array_filter($this->badges, static fn (BadgeResult $badge): bool => $badge->type === BadgeType::Supporter) !== [];
    }

    /**
     * The first-click reveal belongs to the owner only - everyone else always sees the finished badge
     */
    public function needsReveal(BadgeResult $badge): bool
    {
        return $this->ownProfile && $badge->isRevealed() === false && $badge->id !== null;
    }

    /**
     * The medallions in the row: what is revealed. The owner's unrevealed achievements are one call-out above the row
     * instead (Jan 2026-10-11: a row of "?" said neither what they are nor what to do).
     *
     * @return list<BadgeResult>
     */
    public function shownBadges(): array
    {
        return array_values(array_filter($this->badges, fn (BadgeResult $badge): bool => $this->needsReveal($badge) === false));
    }

    public function unrevealedCount(): int
    {
        return count($this->badges) - count($this->shownBadges());
    }

    /**
     * The one-off Early Adopter first, then the highest tier first - Diamond, Platinum, ... - newest first within a tier
     * (Jan 2026-10-12: the same order as the achievements page; pinning is a later feature).
     *
     * @param list<BadgeResult> $badges
     * @return list<BadgeResult>
     */
    private static function bestFirst(array $badges): array
    {
        $rank = static fn (BadgeResult $badge): int => $badge->tier === null ? PHP_INT_MAX : $badge->tier->value;
        usort($badges, static fn (BadgeResult $a, BadgeResult $b): int => [$rank($b), $b->earnedAt] <=> [$rank($a), $a->earnedAt]);

        return $badges;
    }
}
