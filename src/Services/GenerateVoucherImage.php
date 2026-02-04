<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
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
    private const string FONT_PATH = __DIR__ . '/../../assets/fonts/Rubik/Rubik-Regular.ttf';
    private const string FONT_BOLD_PATH = __DIR__ . '/../../assets/fonts/Rubik/Rubik-Bold.ttf';
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
            new RendererStyle(400),
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
        $image = $image->place($qrCode, 'top-left', 2870, 510);

        // Add voucher code below "VOUCHER" text
        $image = $image->text($voucher->code, 1420, 1100, function (FontFactory $font): void {
            $font->filename(self::FONT_BOLD_PATH);
            $font->color('#1d4580');
            $font->size(140);
            $font->align('center');
            $font->valign('middle');
        });

        // Add value text below the code
        $valueText = $this->formatValue($voucher);
        $image = $image->text($valueText, 1420, 1300, function (FontFactory $font): void {
            $font->filename(self::FONT_PATH);
            $font->color('#1d4580');
            $font->size(100);
            $font->align('center');
            $font->valign('middle');
        });

        // Add validity date - positioned on the left side, rotated text
        $validityText = $voucher->validUntil->format('d.m.Y');
        $image = $image->text($validityText, 82, 950, function (FontFactory $font): void {
            $font->filename(self::FONT_PATH);
            $font->color('#1d4580');
            $font->size(60);
            $font->align('center');
            $font->valign('middle');
            $font->angle(90);
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
