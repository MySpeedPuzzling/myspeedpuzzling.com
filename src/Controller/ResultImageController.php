<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

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
        // Generate the image
        $image = $this->imageManager->read(__DIR__ . '/../../test_photo.jpg')
            ->resize(500, 500)
            ->drawRectangle(0, 0, function (RectangleFactory $rectangle) {
                $rectangle->size(500, 500); // width & height of rectangle
                $rectangle->background('rgba(0, 0, 0, 0.8)'); // background color of rectangle
            })
            ->text('Simonka', 250, 30, function (FontFactory $font) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Regular.ttf');
                $font->color('#ffffff');
                $font->size(46);
                $font->align('center');
                $font->valign('top');
            })
            ->text('01:12:23', 250, 80, function (FontFactory $font) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Regular.ttf');
                $font->color('#ffffff');
                $font->size(38);
                $font->align('center');
                $font->valign('top');
            })
            ->text('Název puzzlí', 250, 120, function (FontFactory $font) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Regular.ttf');
                $font->color('#ffffff');
                $font->size(38);
                $font->align('center');
                $font->valign('top');
            })
            ->text('MySpeedPuzzling', 10, 470, function (FontFactory $font) {
                $font->filename(__DIR__ . '/../../assets/fonts/Rubik/Rubik-Regular.ttf');
                $font->color('#ffffff');
                $font->size(20);
                $font->align('left');
                $font->valign('top');
            });

        // Return the image as a response
        $encodedImage = $image->encode();

        return new Response((string) $encodedImage, 200, [
            'Content-Type' => $encodedImage->mimetype(),
            'Content-Disposition' => 'inline',
        ]);
    }
}
