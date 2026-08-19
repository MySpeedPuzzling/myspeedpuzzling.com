<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Intervention\Image\ImageManager;
use Intervention\Image\MediaType;
use Intervention\Image\Typography\FontFactory;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetXpProfile;

/**
 * Level share cards (§1.9) — launch + level-up variants rendered on the 800×800
 * brand background (empty center reserved for the level medallion + name + text).
 * Follows the GetResultImage pipeline: Intervention Image + Rubik + flysystem cache.
 */
readonly final class GetXpShareCard
{
    private const int SIZE = 800;

    public function __construct(
        private ImageManager $imageManager,
        private GetPlayerProfile $getPlayerProfile,
        private GetXpProfile $getXpProfile,
        private Filesystem $filesystem,
        private ClockInterface $clock,
    ) {
    }

    public function forPlayer(string $playerId, string $variant): string
    {
        $player = $this->getPlayerProfile->byId($playerId);
        $xpProfile = $this->getXpProfile->byPlayerId($playerId);

        $noOlderThan = $this->clock->now()->modify('-1 month');
        $path = "players/{$playerId}/xp-card-{$variant}-lv{$xpProfile->level}.png";

        if (
            $this->filesystem->fileExists($path)
            && $this->filesystem->lastModified($path) >= $noOlderThan->getTimestamp()
        ) {
            return $this->filesystem->read($path);
        }

        $size = self::SIZE;
        $centerX = (int) ($size / 2);

        $playerName = $player->playerName ?? sprintf('#%s', strtoupper($player->code));
        $headline = $variant === 'level-up'
            ? sprintf('reached Level %d!', $xpProfile->level)
            : sprintf('is Level %d on the puzzle journey!', $xpProfile->level);
        $signature = sprintf('#%s on MySpeedPuzzling.com', $player->code);

        $image = $this->imageManager->decode((string) file_get_contents(__DIR__ . '/../../public/img/xp/share-card-background-800.png'))
            ->cover($size, $size)
            ->text((string) $xpProfile->level, $centerX, 330, function (FontFactory $font) use ($size) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-ExtraBold.ttf');
                $font->color('#2b3445');
                $font->size((int) ($size / 4));
                $font->align('center', 'center');
            })
            ->text('LEVEL', $centerX, 215, function (FontFactory $font) use ($size) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Bold.ttf');
                $font->color('#4e54c8');
                $font->size((int) ($size / 22));
                $font->align('center', 'center');
            })
            ->text($playerName, $centerX, 460, function (FontFactory $font) use ($size) {
                $font->wrap((int) ($size * 0.8));
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Bold.ttf');
                $font->color('#2b3445');
                $font->size((int) ($size / 16));
                $font->align('center', 'center');
            })
            ->text($headline, $centerX, 525, function (FontFactory $font) use ($size) {
                $font->wrap((int) ($size * 0.85));
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Regular.ttf');
                $font->color('#2b3445');
                $font->size((int) ($size / 22));
                $font->align('center', 'center');
            })
            ->text($signature, $centerX, 600, function (FontFactory $font) use ($size) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Regular.ttf');
                $font->color('#4e54c8');
                $font->size((int) ($size / 28));
                $font->align('center', 'center');
            });

        $encoded = (string) $image->encodeUsingMediaType(MediaType::IMAGE_PNG, quality: 100);

        $this->filesystem->write($path, $encoded);

        return $encoded;
    }
}
