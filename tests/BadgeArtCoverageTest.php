<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\BadgeArtMissing;
use SpeedPuzzling\Web\Services\Badges\BadgeArtManifest;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

/**
 * The committed badge art (public/img/badges/, built by tools/badge-art/build.py) covers every achievement the code
 * knows: a new BadgeType case without its art fails here, not as a missing picture on a profile.
 * Sizes = what badge_art() / badge_art_url() ask for - WebP at 2x of the display sizes 48/72/96/160/240, the e-mail
 * PNG at 96 (+ 144), the SVG.
 */
final class BadgeArtCoverageTest extends TestCase
{
    private const array WEBP_SIZES = [96, 144, 192, 320, 480];

    private const array PNG_SIZES = [96, 144];

    private BadgeArtManifest $manifest;

    protected function setUp(): void
    {
        $this->manifest = new BadgeArtManifest(self::badgesDirectory() . '/manifest.json', false);
    }

    public function testEveryAchievementHasItsArtInEveryFormatAndSize(): void
    {
        $wanted = [];

        foreach (BadgeType::cases() as $type) {
            $tiers = $type->isTiered() ? BadgeTier::cases() : [null];

            foreach ($tiers as $tier) {
                $variant = BadgeArtManifest::variant($tier);
                $wanted[] = [$type, $variant, 'svg', 0];

                foreach (self::WEBP_SIZES as $px) {
                    $wanted[] = [$type, $variant, 'webp', $px];
                }

                foreach (self::PNG_SIZES as $px) {
                    $wanted[] = [$type, $variant, 'png', $px];
                }
            }
        }

        $missing = [];

        foreach ($wanted as [$type, $variant, $format, $px]) {
            try {
                $this->manifest->file($type, $variant, $format, $px);
            } catch (BadgeArtMissing $exception) {
                $missing[] = $exception->getMessage();
            }
        }

        self::assertSame([], $missing);
    }

    public function testATieredAchievementHasNoSingleBadgeAndASingleTierOneNoTiers(): void
    {
        $unexpected = [];

        foreach (BadgeType::cases() as $type) {
            $wrongVariant = $type->isTiered() ? BadgeArtManifest::SINGLE_TIER_VARIANT : BadgeArtManifest::variant(BadgeTier::Bronze);

            try {
                $unexpected[] = $this->manifest->file($type, $wrongVariant, 'svg', 0);
            } catch (BadgeArtMissing) {
                // As it should be
            }
        }

        self::assertSame([], $unexpected);
    }

    /**
     * The detail sheet's medal (tools/badge-art/lib/holo.py): every tier of every tiered achievement turns over to a
     * back plate, Platinum and Diamond have their foil masks.
     */
    public function testEveryTierHasItsMedalBackAndTheTopTiersTheirFoil(): void
    {
        $problems = [];

        foreach (BadgeType::cases() as $type) {
            if ($type->isTiered() === false) {
                continue;
            }

            foreach (BadgeTier::cases() as $tier) {
                $holo = $this->manifest->holo($type, $tier);
                $label = $type->value . '-' . BadgeArtManifest::variant($tier);

                if ($holo === null) {
                    $problems[] = $label . ': no medal data';
                    continue;
                }

                $hasFoil = $holo['foil'] !== null && $holo['rim'] !== null;

                if ($hasFoil !== ($tier === BadgeTier::Platinum || $tier === BadgeTier::Diamond)) {
                    $problems[] = $label . ($hasFoil ? ': a foil below Platinum' : ': no foil masks');
                }
            }
        }

        self::assertSame([], $problems);
    }

    public function testEveryFileOfTheManifestExists(): void
    {
        $missing = array_values(array_filter(
            $this->manifest->files(),
            static fn (string $file): bool => is_file(self::badgesDirectory() . '/' . $file) === false,
        ));

        self::assertSame([], $missing, 'Files listed in public/img/badges/manifest.json but missing on disk');
    }

    private static function badgesDirectory(): string
    {
        return dirname(__DIR__) . '/public/img/badges';
    }
}
