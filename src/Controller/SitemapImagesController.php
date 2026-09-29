<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPuzzleIdsForSitemap;
use SpeedPuzzling\Web\Services\UploaderHelper;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Google Images sitemap for puzzle box photos. Unlike the page sitemaps,
 * each puzzle is listed once, on its EN URL (the hreflang x-default) - image
 * sitemaps do not need per-locale entries and one locale keeps the files 6x
 * smaller.
 */
final class SitemapImagesController extends AbstractController
{
    use SitemapResponseTrait;

    public const int IMAGES_PER_PAGE = 20_000;

    /**
     * Formats Google Images indexes. Anything else (HEIC straight from an iPhone) gets the
     * WebP thumbnail instead of the original.
     *
     * @var list<string>
     */
    private const array INDEXABLE_ORIGINAL_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'];

    public function __construct(
        readonly private GetPuzzleIdsForSitemap $getPuzzleIdsForSitemap,
        readonly private ImageThumbnailTwigExtension $imageThumbnail,
        readonly private UploaderHelper $uploaderHelper,
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
                    '_locale' => 'en',
                    'puzzleId' => $puzzle['id'],
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'lastmod' => $puzzle['lastmod'],
                'image' => $this->largestIndexableImage($puzzle['image']),
            ];
        }

        return $this->xmlResponse('sitemap_images.xml.twig', [
            'entries' => $entries,
        ]);
    }

    /**
     * The uploaded original - the file the puzzle page's gallery opens. imgproxy has no preset
     * above 400 px (puzzle_medium); uploads since Feb 2026 are capped at 2000 px.
     */
    private function largestIndexableImage(string $image): string
    {
        $extension = strtolower(pathinfo($image, PATHINFO_EXTENSION));

        if (in_array($extension, self::INDEXABLE_ORIGINAL_EXTENSIONS, true)) {
            return $this->uploaderHelper->getPublicPath($image);
        }

        return $this->imageThumbnail->thumbnailUrl($image, 'puzzle_medium');
    }
}
