<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use BaconQrCode\Renderer\Color\Alpha;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Intervention\Image\ImageManager;
use Intervention\Image\MediaType;
use Intervention\Image\Typography\FontFactory;
use SpeedPuzzling\Web\Entity\Voucher;
use SpeedPuzzling\Web\Value\VoucherType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

readonly final class GenerateVoucherImage
{
    private const string BACKGROUND_PATH_TEMPLATE = __DIR__ . '/../../assets/img/voucher-%d.png';
    private const string FONT_PATH = __DIR__ . '/../../assets/fonts/CourierPrime/CourierPrime-Regular.ttf';
    private const string FONT_BOLD_PATH = __DIR__ . '/../../assets/fonts/CourierPrime/CourierPrime-Bold.ttf';
    private const string FONT_LAZYDOG_PATH = __DIR__ . '/../../assets/fonts/LazyDog/lazy_dog.ttf';
    private const int MIN_VARIANT = 1;
    private const int MAX_VARIANT = 4;

    public function __construct(
        private ImageManager $imageManager,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param int $variant Background variant number (1-4)
     */
    public function generate(Voucher $voucher, int $variant = 4): string
    {
        $variant = max(self::MIN_VARIANT, min(self::MAX_VARIANT, $variant));

        $claimUrl = $this->urlGenerator->generate(
            'claim_voucher',
            ['code' => $voucher->code, '_locale' => 'en'],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        // Generate QR code
        $renderer = new ImageRenderer(
            new RendererStyle(
                400,
                0, // no margin (side-to-side)
                null,
                null,
                Fill::uniformColor(
                    new Alpha(0, new Rgb(255, 255, 255)), // transparent background
                    new Rgb(0, 0, 0), // black foreground
                ),
            ),
            new ImagickImageBackEnd(),
        );
        $writer = new Writer($renderer);
        $qrCodePng = $writer->writeString($claimUrl);

        $qrCode = $this->imageManager->read($qrCodePng);

        // Load background
        $backgroundPath = sprintf(self::BACKGROUND_PATH_TEMPLATE, $variant);
        $image = $this->imageManager->read($backgroundPath);

        // Image dimensions: 3526x1520
        // QR code position - in the white square on the right side
        $qrSize = 500;
        $qrCode = $qrCode->cover($qrSize, $qrSize);
        $image = $image->place($qrCode, 'top-left', 2384, 517);

        // Voucher code
        $image = $image->text($voucher->code, 3160, 760, function (FontFactory $font): void {
            $font->filename(self::FONT_BOLD_PATH);
            $font->color('#000000');
            $font->size(110);
            $font->align('center');
            $font->valign('middle');
            $font->angle(270);
        });

        // Add value text below the code
        $valueText = $this->formatValue($voucher);
        $image = $image->text($valueText, 1500, 1160, function (FontFactory $font): void {
            $font->filename(self::FONT_LAZYDOG_PATH);
            $font->color('#444343');
            $font->size(190);
            $font->align('center');
            $font->valign('middle');
        });

        // Add validity date - positioned on the left side, rotated text
        $validityText = $voucher->validUntil->format('d.m.Y');
        $image = $image->text($validityText, 362, 760, function (FontFactory $font): void {
            $font->filename(self::FONT_BOLD_PATH);
            $font->color('#000000');
            $font->size(85);
            $font->align('center');
            $font->valign('middle');
            $font->angle(270);
        });

        return (string) $image->encodeByMediaType(MediaType::IMAGE_PNG, quality: 100);
    }

    private function formatValue(Voucher $voucher): string
    {
        return match ($voucher->voucherType) {
            VoucherType::FreeMonths => sprintf(
                '%d %s free',
                $voucher->monthsValue,
                $voucher->monthsValue === 1 ? 'month' : 'months',
            ),
            VoucherType::PercentageDiscount => sprintf('%d%% discount', $voucher->percentageDiscount),
        };
    }
}
