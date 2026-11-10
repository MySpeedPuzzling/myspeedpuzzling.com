<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use AsyncAws\Core\Exception\Exception as AsyncAwsException;
use Imagick;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Results\ResultImage;
use SpeedPuzzling\Web\Results\SolvedPuzzleDetail;
use SpeedPuzzling\Web\Value\SolvingTime;
use Sentry\Tracing\SpanContext;

use function Sentry\trace;

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
        private ResultImageCache $resultImageCache,
        private ResultImageVersion $resultImageVersion,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PuzzleSolvingTimeNotFound
     */
    public function forSolvingTime(string $timeId): ResultImage
    {
        $solvingTime = $this->getPlayerSolvedPuzzles->byTimeId($timeId);
        $player = $this->getPlayerProfile->byId($solvingTime->playerId);
        // A public, unauthenticated image that is kept for a while (ResultImageCache): it follows the player's own
        // setting, never who is looking - allow-listed friends get the nameless one as well. The
        // separate path keeps a copy rendered while the profile was public from being served.
        $isPrivateProfile = $this->resultImageVersion->isPrivateProfile($solvingTime->playerId);
        // Kept under what it shows: a changed result never gets the card of before (ResultImageVersion)
        $version = $this->resultImageVersion->of($solvingTime, $player);
        $path = $isPrivateProfile
            ? "players/$player->playerId/results/$timeId-$version-hidden.jpg"
            : "players/$player->playerId/results/$timeId-$version.jpg";

        $kept = $this->resultImageCache->read($path);

        if ($kept !== null) {
            return new ResultImage($kept->content, withPlaceholder: false, drawnAt: $kept->keptAt);
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
        [$imageContent, $withPlaceholder] = $this->timed('photo read', fn (): array => $this->photoFor($solvingTime));

        $regular = __DIR__ . '/../../assets/fonts/Rubik/Rubik-Regular.ttf';
        $light = __DIR__ . '/../../assets/fonts/Rubik/Rubik-Light.ttf';
        $centered = static function (string $file, int $fontSize): callable {
            return static function (FontFactory $font) use ($file, $fontSize): void {
                $font->filename($file);
                $font->size($fontSize);
                $font->align('center', 'top');
            };
        };
        $yOffset = $puzzleNameHeight + $puzzleNameOffset + $offsetTop;

        $image = $this->timed('draw', function () use ($imageContent, $logo, $size, $regular, $light, $centered, $yOffset, $puzzleName, $puzzleNameOffset, $offsetTop, $fontSizeBig, $fontSizeNormal, $fontSizeSmall, $fontSizeLittle, $brandName, $solvingTime, $ppmText, $isPrivateProfile, $player, $rankingText, $signature): ImageInterface {
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
                });

            ResultImageText::draw($image, $puzzleName, $size / 2, 100 + $puzzleNameOffset + $offsetTop, function (FontFactory $font) use ($centered, $regular, $fontSizeNormal, $size): void {
                $centered($regular, $fontSizeNormal)($font);
                $font->wrap((int) ($size * 0.97));
                $font->lineHeight(1.4);
            });
            ResultImageText::draw($image, $brandName, $size / 2, 115 + $yOffset, $centered($light, $fontSizeSmall));
            ResultImageText::draw($image, $solvingTime->piecesCount . ' pieces', $size / 2, 170 + $yOffset, $centered($light, $fontSizeNormal));
            ResultImageText::draw($image, $solvingTime->time !== null ? $this->puzzlingTimeFormatter->formatTime($solvingTime->time) : 'Relax', $size / 2, 260 + $yOffset, $centered($regular, $fontSizeBig));
            ResultImageText::draw($image, $ppmText, $size / 2, 340 + $yOffset, $centered($regular, $fontSizeSmall));
            ResultImageText::draw($image, $isPrivateProfile ? '' : ($player->playerName ?? ''), $size / 2, 400 + $yOffset, $centered($regular, $fontSizeSmall));
            ResultImageText::draw($image, $rankingText, $size / 2, 450 + $yOffset, $centered($light, $fontSizeSmall));
            ResultImageText::draw($image, $signature, 80, $size - $fontSizeLittle - 18, static function (FontFactory $font) use ($light, $fontSizeLittle): void {
                $font->filename($light);
                $font->size($fontSizeLittle);
                $font->align('left', 'top');
            });

            return $image->insert($logo, 10, 10, 'bottom-left');
        });

        // The share image is drawn over the player's photo and would otherwise carry its EXIF - GPS
        // position included - into a public image
        $native = $image->core()->native();
        assert($native instanceof Imagick);
        ImageMetadata::strip($native);

        $fileContent = $this->timed('encode', static fn (): string => ResultImageJpeg::encode($native));

        // A placeholder version is never stored: once the real photo is there, it is used
        if ($withPlaceholder === false) {
            $this->resultImageCache->write($path, $fileContent);
        }

        return new ResultImage(
            $fileContent,
            $withPlaceholder,
            drawnAt: $withPlaceholder ? null : $this->clock->now(),
        );
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function timed(string $step, callable $work): mixed
    {
        return trace(
            static fn (): mixed => $work(),
            SpanContext::make()->setOp('result_image')->setDescription($step),
        );
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
        } catch (FilesystemException | AsyncAwsException $exception) {
            // AsyncAws leaks its own exceptions on a network failure besides FilesystemException
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
