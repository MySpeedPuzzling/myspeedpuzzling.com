<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\Typography\FontFactory;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ResetStopwatch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ResultImageController extends AbstractController
{
    public function __construct(
        readonly private ImageManager $imageManager,
    ) {
    }

    #[Route(
        path: [
            'en' => '/en/result-image/',
        ],
        name: 'result_image',
    )]
    public function __invoke(
        #[CurrentUser]
        UserInterface $user,
    ): Response
    {
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
            'Content-Disposition' => 'inline; filename="output.png"',
        ]);
    }
}
