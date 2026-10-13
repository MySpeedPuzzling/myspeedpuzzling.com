<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\LevelTable;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The XP level ring around a player's avatar (docs/features/xp-levels/launch-2026-10.md, "Profile header" and
 * "Navbar"): an arc showing the progress toward the next level, in the colour of the player's level band - one per 10
 * levels, gold at 50 (band(), components/_xp.scss .xp-level-band-*, design-spec §9), so a level reads at first sight.
 *
 * Variants:
 *  - header: the player header's avatar - the arc, a "Lv 12" tab under it, the whole avatar a link (own XP history,
 *    or how levels work for anybody else)
 *  - bar: the compact bar's small avatar - the arc only
 *  - nav: the account menu's avatar of the signed-in player (the top bar's avatar ▾, the Menu's You card) - the arc only
 *
 * Reads the player's profile row only (xp_total, level, experience_system_opted_out ride on it) - no query. Renders
 * its content unchanged while XP is not visible to this viewer (XpFeatureGate: launch ready or admin), when the player
 * opted out of the experience system, or when a private profile is hidden from this viewer (PlayerProfile::$isPrivate -
 * PrivateProfileAccess decided it).
 */
#[AsTwigComponent]
final class XpRing
{
    public const string VARIANT_HEADER = 'header';
    public const string VARIANT_BAR = 'bar';
    public const string VARIANT_NAV = 'nav';

    public null|PlayerProfile $player = null;

    public string $variant = self::VARIANT_HEADER;

    public bool $visible = false;

    public bool $isOwn = false;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
    }

    #[PostMount]
    public function decide(): void
    {
        $player = $this->player;

        if ($player === null || $player->experienceSystemOptedOut) {
            return;
        }

        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($this->xpFeatureGate->isVisibleFor($viewer) === false) {
            return;
        }

        $this->isOwn = $viewer?->playerId === $player->playerId;
        $this->visible = $player->isPrivate === false || $this->isOwn;
    }

    /**
     * The level's colour band: 1 = Level 1-9, 2 = 10-19 ... 5 = 40-49, 6 = Level 50. The one rule - templates without
     * the component (the reveal's level medallion) compute the same `level >= 50 ? 6 : level // 10 + 1`.
     */
    public static function band(int $level): int
    {
        if ($level >= LevelTable::MAX_LEVEL) {
            return 6;
        }

        return intdiv(max($level, 1), 10) + 1;
    }

    public function bandClass(): string
    {
        return 'xp-level-band-' . self::band($this->player->level ?? 1);
    }

    /**
     * 0.0-1.0 toward the next level, rounded for the inline style; full at the top level
     */
    public function progress(): float
    {
        return round($this->player?->levelProgress() ?? 0.0, 3);
    }
}
