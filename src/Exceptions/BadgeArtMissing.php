<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * A badge picture the templates ask for does not exist in public/img/badges/ - a programming error: the art is built
 * for every achievement and tier by tools/badge-art/build.py, which also writes the manifest this is checked against.
 * Thrown in debug only; production logs a warning and leaves the picture out (BadgeArtTwigExtension).
 */
final class BadgeArtMissing extends \LogicException
{
    public static function unknownType(string $type): self
    {
        return new self(sprintf('"%s" is not an achievement (BadgeType) - there is no badge art for it.', $type));
    }

    public static function unknownTier(int $tier): self
    {
        return new self(sprintf('Tier %d does not exist - badge art has tiers 1-5, or null/0 for a single-tier achievement.', $tier));
    }

    public static function unknownFormat(string $format): self
    {
        return new self(sprintf('Badge art format "%s" does not exist - use webp, png or svg.', $format));
    }

    public static function notInManifest(string $file, string $manifestPath): self
    {
        return new self(sprintf(
            'No badge art "%s" in %s. A tiered achievement has t1-t5, a single-tier one only "single"; the sizes are the ones tools/badge-art/build.py renders. New achievement? Add its icon and run the build (tools/badge-art/README.md).',
            $file,
            $manifestPath,
        ));
    }

    public static function unreadableManifest(string $manifestPath, string $reason): self
    {
        return new self(sprintf(
            'Badge art manifest %s can not be used (%s) - generate the art with tools/badge-art/build.py.',
            $manifestPath,
            $reason,
        ));
    }
}
