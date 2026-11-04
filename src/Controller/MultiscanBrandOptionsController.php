<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The brand picker of multiscan's quick-add (multiscan_picker_controller.js, mode "brand"): every brand, approved or
 * not - one left out gets typed again as a duplicate (docs/features/brand-duplicates.md) - most puzzles first, with
 * its logo. Fetched once when the picker opens instead of being rendered into every re-render of the tray
 * (2,000+ brands).
 */
final class MultiscanBrandOptionsController extends AbstractController
{
    public function __construct(
        readonly private GetManufacturers $getManufacturers,
        readonly private ImageThumbnailTwigExtension $imageThumbnail,
    ) {
    }

    #[Route(
        path: '/multiscan-brands.json',
        name: 'multiscan_brand_options',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(): JsonResponse
    {
        $brands = [];

        // Already ordered by puzzle count (most common first), then by name
        foreach ($this->getManufacturers->allIncludingUnapproved() as $manufacturer) {
            $brands[] = [
                'value' => $manufacturer->manufacturerId,
                'text' => $manufacturer->manufacturerName,
                'count' => $manufacturer->puzzlesCount,
                'logo' => $manufacturer->manufacturerLogo !== null
                    ? $this->imageThumbnail->thumbnailUrl($manufacturer->manufacturerLogo, 'puzzle_small')
                    : null,
                'eanPrefix' => $manufacturer->manufacturerEanPrefix ?? '',
            ];
        }

        $response = new JsonResponse($brands);
        $response->setPrivate();
        $response->setMaxAge(600);

        return $response;
    }
}
