<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Intervention\Image\Geometry\Factories\CircleFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\MediaType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

readonly final class GeneratePuzzleQrCode
{
    private const string LOGO_PATH = __DIR__ . '/../../public/img/speedpuzzling-logo-mini.png';
    private const int QR_SIZE = 600;
    private const int LOGO_SIZE = 110;
    private const int LOGO_PADDING = 15;

    public function __construct(
        private ImageManager $imageManager,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function generate(string $puzzleId): string
    {
        $url = $this->urlGenerator->generate(
            'puzzle_qr_redirect',
            ['puzzleId' => $puzzleId],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $renderer = new ImageRenderer(
            new RendererStyle(
                self::QR_SIZE,
                2,
                null,
                null,
                Fill::uniformColor(
                    new Rgb(255, 255, 255),
                    new Rgb(51, 51, 51),
                ),
            ),
            new ImagickImageBackEnd(),
        );

        $writer = new Writer($renderer);
        $qrCodePng = $writer->writeString($url, ecLevel: ErrorCorrectionLevel::H());

        $qrImage = $this->imageManager->read($qrCodePng);

        // Draw white circle background for logo
        $centerX = (int) ($qrImage->width() / 2);
        $centerY = (int) ($qrImage->height() / 2);
        $backgroundRadius = self::LOGO_SIZE / 2 + self::LOGO_PADDING;

        $qrImage = $qrImage->drawCircle($centerX, $centerY, function (CircleFactory $circle) use ($backgroundRadius): void {
            $circle->radius((int) $backgroundRadius);
            $circle->background('ffffff');
        });

        // Load and resize logo (scaleDown preserves aspect ratio without cropping)
        $logo = $this->imageManager->read(file_get_contents(self::LOGO_PATH));
        $logo = $logo->scaleDown(self::LOGO_SIZE, self::LOGO_SIZE);

        // Place logo in the center of the QR code
        $qrImage = $qrImage->place($logo, 'center');

        return (string) $qrImage->encodeByMediaType(MediaType::IMAGE_PNG, quality: 100);
    }
}
