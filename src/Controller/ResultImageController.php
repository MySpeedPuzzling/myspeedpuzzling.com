<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\Typography\FontFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ResultImageController extends AbstractController
{
    public function __construct(
        readonly private ImageManager $imageManager,
    ) {
    }

    #[Route(path: '/result-image', name: 'result_image')]
    public function sharingAction(): Response
    {
        // Render the HTML page with embedded image and meta tags
        return $this->render('result_image.html.twig', [
            'image_url' => $this->generateUrl('result_image_file', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'title' => 'My Image Title',
            'description' => 'A description of the image goes here.',
        ]);
    }

    #[Route('/result-image-file', name: 'result_image_file')]
    public function resultImageFileAction(): Response
    {
        $size = 800;
        $fontSizeBig = (int) ($size / 10);
        $fontSizeNormal = (int) ($size / 14);
        $fontSizeSmall = (int) ($size / 20);
        $fontSizeLittle = (int) ($size / 30);

        $logo = $this->imageManager->read(__DIR__ . '/../../public/img/speedpuzzling-logo.png')
            ->scaleDown(50, 50)
            ->sharpen(3);

        // Generate the image
        $puzzleName = 'Foul Play & Cabernet - A Mystery Jigsaw Thriller with a Secret Puzzle Image';
        $puzzleNameHeight = (int) (ceil(strlen($puzzleName) / 26) * $fontSizeNormal);

        $image = $this->imageManager->read(__DIR__ . '/../../test_photo.jpg')
            ->cover($size, $size)
            ->blur(4)
            ->drawRectangle(0, 0, function (RectangleFactory $rectangle) use ($size) {
                $rectangle->size($size, $size);
                $rectangle->background('rgba(236, 114, 111, 0.4)');
            })
            ->drawRectangle(0, 0, function (RectangleFactory $rectangle) use ($size) {
                $rectangle->size($size, $size);
                $rectangle->background('rgba(0, 0, 0, 0.6)');
            })
            ->text($puzzleName, $size / 2, 100, function (FontFactory $font) use ($fontSizeNormal, $size) {
                $font->wrap((int) ($size * 0.9));
                $font->lineHeight(1.4);
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Regular.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeNormal);
                $font->align('center');
                $font->valign('top');
            })
            ->text('San Francisco Museum of Modern Art', $size / 2, 120 + $puzzleNameHeight, function (FontFactory $font) use ($fontSizeSmall) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Light.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeSmall);
                $font->align('center');
                $font->valign('top');
            })
            ->text('500 pieces', $size / 2, 190 + $puzzleNameHeight, function (FontFactory $font) use ($fontSizeNormal) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Light.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeNormal);
                $font->align('center');
                $font->valign('top');
            })
            ->text('01:12:23', $size / 2, 300 + $puzzleNameHeight, function (FontFactory $font) use ($fontSizeBig) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Regular.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeBig);
                $font->align('center');
                $font->valign('top');
            })
            ->text('Rose Robbins @queens_of_pieces', $size / 2, 390 + $puzzleNameHeight, function (FontFactory $font) use ($fontSizeSmall) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Regular.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeSmall);
                $font->align('center');
                $font->valign('top');
            })
            ->text('Rank 20 of 50', $size / 2, 440 + $puzzleNameHeight, function (FontFactory $font) use ($fontSizeSmall) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Light.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeSmall);
                $font->align('center');
                $font->valign('top');
            })
            ->text('#simonka on MySpeedPuzzling.com', 65, $size - $fontSizeLittle - 18, function (FontFactory $font) use ($fontSizeLittle) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Light.ttf');
                $font->color('#ffffff');
                $font->stroke('#000000', 1);
                $font->size($fontSizeLittle);
                $font->align('left');
                $font->valign('top');
            })
            ->place($logo, 'bottom-left', 10, 10);

        // Return the image as a response
        $encodedImage = $image->encodeByExtension(quality: 100);

        return new Response((string) $encodedImage, 200, [
            'Content-Type' => $encodedImage->mimetype(),
            'Content-Disposition' => 'inline',
        ]);
    }
}
