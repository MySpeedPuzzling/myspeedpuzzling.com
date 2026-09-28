<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\MediaType;
use Intervention\Image\Typography\FontFactory;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\ResultImage;
use SpeedPuzzling\Web\Results\SolvedPuzzleDetail;
use SpeedPuzzling\Web\Value\SolvingTime;

readonly final class GetResultImage
{
    private const string PLACEHOLDER_PHOTO = __DIR__ . '/../../public/img/placeholder-puzzle.jpg';

    public function __construct(
        private ImageManager $imageManager,
        private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        private PuzzlingTimeFormatter $puzzlingTimeFormatter,
        private GetPlayerProfile $getPlayerProfile,
        private GetRanking $getRanking,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private PlayerRepository $playerRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws PuzzleSolvingTimeNotFound
     * @throws \League\Flysystem\FilesystemException the cache lookup goes to object storage
     * @throws \AsyncAws\Core\Exception\Exception fileExists() leaks raw AsyncAws exceptions on network failure
     */
    public function forSolvingTime(string $timeId): ResultImage
    {
        $solvingTime = $this->getPlayerSolvedPuzzles->byTimeId($timeId);
        $player = $this->getPlayerProfile->byId($solvingTime->playerId);
        $noOlderThan = $this->clock->now()->modify('-1 month');
        // A public, unauthenticated image that is cached in storage: it follows the player's own
        // setting, never who is looking - allow-listed friends get the nameless one as well. The
        // separate path keeps a copy rendered while the profile was public from being served.
        $isPrivateProfile = $this->playerRepository->get($solvingTime->playerId)->isPrivate;
        $path = $isPrivateProfile
            ? "players/$player->playerId/results/$timeId-hidden.png"
            : "players/$player->playerId/results/$timeId.png";

        if (
            $this->filesystem->fileExists($path)
            && $this->filesystem->lastModified($path) >= $noOlderThan->getTimestamp()
        ) {
            return new ResultImage($this->filesystem->read($path), withPlaceholder: false);
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

        $logo = $this->imageManager->decode(__DIR__ . '/../../public/img/speedpuzzling-logo.png')
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
        [$imageContent, $withPlaceholder] = $this->photoFor($solvingTime);

        $image = $this->imageManager->decode($imageContent)
            ->cover($size, $size)
            ->drawRectangle(function (RectangleFactory $rectangle) use ($size) {
                $rectangle->at(0, 0);
                $rectangle->size($size, $size);
                $rectangle->background('rgba(250, 114, 111, 0.44)');
            })
            ->drawRectangle(function (RectangleFactory $rectangle) use ($size) {
                $rectangle->at(0, 0);
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
                $font->align('center', 'top');
            })
            ->text($brandName, $size / 2, 115 + $puzzleNameHeight + $puzzleNameOffset + $offsetTop, function (FontFactory $font) use ($fontSizeSmall) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Light.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeSmall);
                $font->align('center', 'top');
            })
            ->text($solvingTime->piecesCount . ' pieces', $size / 2, 170 + $puzzleNameHeight + $puzzleNameOffset + $offsetTop, function (FontFactory $font) use ($fontSizeNormal) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Light.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeNormal);
                $font->align('center', 'top');
            })
            ->text($solvingTime->time !== null ? $this->puzzlingTimeFormatter->formatTime($solvingTime->time) : 'Relax', $size / 2, 260 + $puzzleNameHeight + $puzzleNameOffset + $offsetTop, function (FontFactory $font) use ($fontSizeBig) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Regular.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeBig);
                $font->align('center', 'top');
            })
            ->text($ppmText, $size / 2, 340 + $puzzleNameHeight + $puzzleNameOffset + $offsetTop, function (FontFactory $font) use ($fontSizeSmall) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Regular.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeSmall);
                $font->align('center', 'top');
            })
            ->text($isPrivateProfile ? '' : ($player->playerName ?? ''), $size / 2, 400 + $puzzleNameHeight + $puzzleNameOffset + $offsetTop, function (FontFactory $font) use ($fontSizeSmall) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Regular.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeSmall);
                $font->align('center', 'top');
            })
            ->text($rankingText, $size / 2, 450 + $puzzleNameHeight + $puzzleNameOffset + $offsetTop, function (FontFactory $font) use ($fontSizeSmall) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Light.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeSmall);
                $font->align('center', 'top');
            })
            ->text($signature, 80, $size - $fontSizeLittle - 18, function (FontFactory $font) use ($fontSizeLittle) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Light.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeLittle);
                $font->align('left', 'top');
            })
            ->insert($logo, 10, 10, 'bottom-left');


        $fileContent = (string) $image->encodeUsingMediaType(MediaType::IMAGE_PNG, quality: 100);

        // A placeholder version is never stored: once the real photo is there, it is used
        if ($withPlaceholder === false) {
            $this->filesystem->write($path, $fileContent);
        }

        return new ResultImage($fileContent, $withPlaceholder);
    }

    /**
     * The photo the share image is drawn over - the finished-puzzle photo, else the box. A missing
     * one never fails the image (it is fetched by social crawlers, a 500 helps nobody): the
     * placeholder stands in. A photo the database knows but storage does not have, or a puzzle
     * without any image, is a data problem worth a look (warning); an image held back until a
     * competition round starts is by design (info).
     *
     * @return array{0: string, 1: bool} photo bytes, drawn with the placeholder
     */
    private function photoFor(SolvedPuzzleDetail $solvingTime): array
    {
        $imagePath = $solvingTime->finishedPuzzlePhoto ?? $solvingTime->puzzleImage;
        $context = [
            'timeId' => $solvingTime->timeId,
            'puzzleId' => $solvingTime->puzzleId,
            'imagePath' => $imagePath,
        ];

        if ($imagePath === null) {
            if ($solvingTime->puzzleImageHidden) {
                $this->logger->info('Result image drawn with the placeholder - the puzzle image is hidden until its round starts', $context);
            } else {
                $this->logger->warning('Result image drawn with the placeholder - the puzzle has no image', $context);
            }

            return [$this->placeholderPhoto(), true];
        }

        try {
            return [$this->filesystem->read($imagePath), false];
        } catch (FilesystemException $exception) {
            $this->logger->warning('Result image drawn with the placeholder - the photo could not be read from storage', $context + [
                'exception' => $exception,
            ]);

            return [$this->placeholderPhoto(), true];
        }
    }

    private function placeholderPhoto(): string
    {
        $content = file_get_contents(self::PLACEHOLDER_PHOTO);
        assert($content !== false);

        return $content;
    }
}
