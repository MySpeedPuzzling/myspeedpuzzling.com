<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\MediaType;
use Intervention\Image\Typography\FontFactory;
use League\Flysystem\Filesystem;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use SpeedPuzzling\Web\Value\SolvingTime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ResultImageController extends AbstractController
{
    public function __construct(
        readonly private ImageManager $imageManager,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private PuzzlingTimeFormatter $puzzlingTimeFormatter,
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetRanking $getRanking,
        readonly private Filesystem $filesystem,
    ) {
    }

    #[Route('/result-image/{timeId}', name: 'result_image')]
    public function __invoke(string $timeId): Response
    {
        // TODO: check if is saved on disk, if yes, use it

        $solvingTime = $this->getPlayerSolvedPuzzles->byTimeId($timeId);
        $player = $this->getPlayerProfile->byId($solvingTime->playerId);

        if ($solvingTime->players === null) {
            $ranking = $this->getRanking->ofPuzzleForPlayer($solvingTime->puzzleId, $player->playerId);
            $rankingText = sprintf('Rank %s of %s', $ranking->rank, $ranking->totalPlayers);
        } else {
            $rankingText = count($solvingTime->players) === 1 ? 'Pair puzzling' : 'Group puzzling';
        }

        $size = 800;
        $fontSizeBig = (int) ($size / 10);
        $fontSizeNormal = (int) ($size / 14);
        $fontSizeSmall = (int) ($size / 20);
        $fontSizeLittle = (int) ($size / 30);

        $logo = $this->imageManager->read(__DIR__ . '/../../public/img/speedpuzzling-logo.png')
            ->scaleDown(50, 50)
            ->sharpen(3);

        $ppm = (new SolvingTime($solvingTime->time))->calculatePpm(
            $solvingTime->piecesCount,
            $solvingTime->players !== null ? count($solvingTime->players) : 1,
        );

        // Generate the image
        // $puzzleName = 'Foul Play & Cabernet - A Mystery Jigsaw Thriller with a Secret Puzzle Image';
        $puzzleName = $solvingTime->puzzleName;
        // $brandName = 'San Francisco Museum of Modern Art';
        $brandName = $solvingTime->manufacturerName;
        $signature = sprintf('#%s on MySpeedPuzzling.com', $player->code);
        $ppmText = sprintf('%s PPM', $ppm);
        $offsetTop = 20;


        $puzzleNameLines = (int) ceil(strlen($puzzleName) / 25);
        $puzzleNameOffset = (int) ((3 - $puzzleNameLines) * $fontSizeNormal / 3);
        $puzzleNameHeight = $puzzleNameLines * $fontSizeNormal;
        $imagePath = $solvingTime->finishedPuzzlePhoto ?? $solvingTime->puzzleImage ?? throw $this->createNotFoundException();
        $imageContent = $this->filesystem->read($imagePath);

        $image = $this->imageManager->read($imageContent)
            ->cover($size, $size)
            ->blur(4)
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
            ->text($this->puzzlingTimeFormatter->formatTime($solvingTime->time), $size / 2, 260 + $puzzleNameHeight + $puzzleNameOffset + $offsetTop, function (FontFactory $font) use ($fontSizeBig) {
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
            ->text($signature, 65, $size - $fontSizeLittle - 18, function (FontFactory $font) use ($fontSizeLittle) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Light.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeLittle);
                $font->align('left');
                $font->valign('top');
            })
            ->place($logo, 'bottom-left', 10, 10);

        // Return the image as a response
        $encodedImage = $image->encodeByMediaType(MediaType::IMAGE_PNG, quality: 100);

        return new Response((string) $encodedImage, 200, [
            'Content-Type' => $encodedImage->mimetype(),
            'Content-Disposition' => 'inline',
        ]);
    }
}
