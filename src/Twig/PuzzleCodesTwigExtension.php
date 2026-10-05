<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * A puzzle's codes on the page (docs/features/puzzle-names/README.md, decision 6), from the stored lists:
 * - `puzzle_eans(ean)` - every barcode as printed under it (a UPC-A with its 12th digit, EanList::display()),
 * - `puzzle_brand_codes(identification_number)` - every brand code (BrandCodeList::display()),
 * - `puzzle_gtins(ean)` - the valid barcodes for structured data (EanList::gtins()).
 */
final class PuzzleCodesTwigExtension extends AbstractExtension
{
    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('puzzle_eans', static fn (null|string $ean): array => EanList::fromStored($ean)->display()),
            new TwigFunction('puzzle_brand_codes', static fn (null|string $codes): array => BrandCodeList::fromStored($codes)->display()),
            new TwigFunction('puzzle_gtins', static fn (null|string $ean): array => EanList::fromStored($ean)->gtins()),
        ];
    }
}
