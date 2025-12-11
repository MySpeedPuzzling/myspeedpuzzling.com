<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\MediaType;
use Intervention\Image\Typography\FontFactory;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Value\SolvingTime;

readonly final class GetResultImage
{
    public function __construct(
        private ImageManager $imageManager,
        private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        private PuzzlingTimeFormatter $puzzlingTimeFormatter,
        private GetPlayerProfile $getPlayerProfile,
        private GetRanking $getRanking,
        private Filesystem $filesystem,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PuzzleSolvingTimeNotFound
     */
    public function forSolvingTime(string $timeId): string
    {
        $solvingTime = $this->getPlayerSolvedPuzzles->byTimeId($timeId);
        $player = $this->getPlayerProfile->byId($solvingTime->playerId);
        $noOlderThan = $this->clock->now()->modify('-1 month');
        $path = "players/$player->playerId/results/$timeId.png";

        if (
            $this->filesystem->fileExists($path)
            && $this->filesystem->lastModified($path) >= $noOlderThan->getTimestamp()
        ) {
            return $this->filesystem->read($path);
        }

        $rankingText = '';

        if ($solvingTime->players === null) {
            $ranking = $this->getRanking->ofPuzzleForPlayer($solvingTime->puzzleId, $player->playerId);

            if ($ranking !== null && $ranking->totalPlayers > 2) {
                $rankingText = sprintf('Rank %s of %s', $ranking->rank, $ranking->totalPlayers);
            }
        } else {
            $rankingText = count($solvingTime->players) === 1 ? 'Pair puzzling' : 'Group puzzling';
        }

        $size = 800;
        $fontSizeBig = (int) ($size / 10);
        $fontSizeNormal = (int) ($size / 14);
        $fontSizeSmall = (int) ($size / 20);
        $fontSizeLittle = (int) ($size / 30);

        $logo = $this->imageManager->read(__DIR__ . '/../../public/img/speedpuzzling-logo.png')
            ->scaleDown(60, 60)
            ->sharpen(3);

        $ppm = (new SolvingTime($solvingTime->time))->calculatePpm(
            $solvingTime->piecesCount,
            $solvingTime->players !== null ? count($solvingTime->players) : 1,
        );

        $puzzleName = $solvingTime->puzzleName;
        $brandName = $solvingTime->manufacturerName;
        // $puzzleName = 'Foul Play & Cabernet - A Mystery Jigsaw Thriller with a Secret Puzzle Image';
        // $brandName = 'San Francisco Museum of Modern Art';
        $signature = sprintf('#%s on MySpeedPuzzling.com', $player->code);
        $ppmText = sprintf('%s PPM', $ppm);
        $offsetTop = 20;

        $puzzleNameLines = (int) ceil(strlen($puzzleName) / 25);
        $puzzleNameOffset = (int) ((3 - $puzzleNameLines) * $fontSizeNormal / 3);
        $puzzleNameHeight = $puzzleNameLines * $fontSizeNormal;
        $imagePath = $solvingTime->finishedPuzzlePhoto ?? $solvingTime->puzzleImage ?? throw new \Exception('Image missing');
        $imageContent = $this->filesystem->read($imagePath);

        $image = $this->imageManager->read($imageContent)
            ->cover($size, $size)
            ->drawRectangle(0, 0, function (RectangleFactory $rectangle) use ($size) {
                $rectangle->size($size, $size);
                $rectangle->background('rgba(250, 114, 111, 0.44)');
            })
            ->drawRectangle(0, 0, function (RectangleFactory $rectangle) use ($size) {
                $rectangle->size($size, $size);
                $rectangle->background('rgba(0, 0, 0, 0.55)');
            })
            ->text($puzzleName, $size / 2, 100 + $puzzleNameOffset + $offsetTop, function (FontFactory $font) use ($fontSizeNormal, $size) {
                $font->wrap((int) ($size * 0.97));
                $font->lineHeight(1.4);
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Regular.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeNormal);
                $font->align('center');
                $font->valign('top');
            })
            ->text($brandName, $size / 2, 115 + $puzzleNameHeight + $puzzleNameOffset + $offsetTop, function (FontFactory $font) use ($fontSizeSmall) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Light.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeSmall);
                $font->align('center');
                $font->valign('top');
            })
            ->text($solvingTime->piecesCount . ' pieces', $size / 2, 170 + $puzzleNameHeight + $puzzleNameOffset + $offsetTop, function (FontFactory $font) use ($fontSizeNormal) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Light.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeNormal);
                $font->align('center');
                $font->valign('top');
            })
            ->text($solvingTime->time !== null ? $this->puzzlingTimeFormatter->formatTime($solvingTime->time) : 'Relax', $size / 2, 260 + $puzzleNameHeight + $puzzleNameOffset + $offsetTop, function (FontFactory $font) use ($fontSizeBig) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Regular.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeBig);
                $font->align('center');
                $font->valign('top');
            })
            ->text($ppmText, $size / 2, 340 + $puzzleNameHeight + $puzzleNameOffset + $offsetTop, function (FontFactory $font) use ($fontSizeSmall) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Regular.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeSmall);
                $font->align('center');
                $font->valign('top');
            })
            ->text($player->playerName ?? '', $size / 2, 400 + $puzzleNameHeight + $puzzleNameOffset + $offsetTop, function (FontFactory $font) use ($fontSizeSmall) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Regular.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeSmall);
                $font->align('center');
                $font->valign('top');
            })
            ->text($rankingText, $size / 2, 450 + $puzzleNameHeight + $puzzleNameOffset + $offsetTop, function (FontFactory $font) use ($fontSizeSmall) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Light.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeSmall);
                $font->align('center');
                $font->valign('top');
            })
            ->text($signature, 80, $size - $fontSizeLittle - 18, function (FontFactory $font) use ($fontSizeLittle) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Light.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeLittle);
                $font->align('left');
                $font->valign('top');
            })
            ->place($logo, 'bottom-left', 10, 10);


        $fileContent = (string) $image->encodeByMediaType(MediaType::IMAGE_PNG, quality: 100);

        $this->filesystem->write($path, $fileContent);

        return $fileContent;
    }
}
