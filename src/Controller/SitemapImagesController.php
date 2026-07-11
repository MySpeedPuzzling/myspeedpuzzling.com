<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPuzzleIdsForSitemap;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Google Images sitemap for puzzle box photos. Unlike the page sitemaps,
 * each puzzle is listed once (cs unprefixed canonical only) - image sitemaps
 * do not need per-locale entries and one locale keeps the files 6x smaller.
 */
final class SitemapImagesController extends AbstractController
{
    use SitemapResponseTrait;

    public const int IMAGES_PER_PAGE = 20_000;

    public function __construct(
        readonly private GetPuzzleIdsForSitemap $getPuzzleIdsForSitemap,
        readonly private ImageThumbnailTwigExtension $imageThumbnail,
    ) {
    }

    #[Route(
        path: '/sitemap-images-{page}.xml',
        name: 'sitemap_images',
        requirements: ['page' => '[1-9]\d*'],
    )]
    public function __invoke(int $page): Response
    {
        $puzzles = $this->getPuzzleIdsForSitemap->approvedPageWithImages(
            limit: self::IMAGES_PER_PAGE,
            offset: ($page - 1) * self::IMAGES_PER_PAGE,
        );

        if ($puzzles === [] && $page > 1) {
            throw $this->createNotFoundException();
        }

        $entries = [];

        foreach ($puzzles as $puzzle) {
            $entries[] = [
                'loc' => $this->generateUrl('puzzle_detail', [
                    '_locale' => 'cs',
                    'puzzleId' => $puzzle['id'],
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'lastmod' => $puzzle['lastmod'],
                'image' => $this->imageThumbnail->thumbnailUrl($puzzle['image'], 'puzzle_medium'),
            ];
        }

        return $this->xmlResponse('sitemap_images.xml.twig', [
            'entries' => $entries,
        ]);
    }
}
