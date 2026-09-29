<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SitemapImagesControllerTest extends WebTestCase
{
    public function testListsEachPhotoOnceOnTheEnglishPageWithTheStrippedThumbnail(): void
    {
        $browser = self::createClient();
        $this->setImage(PuzzleFixture::PUZZLE_500_01, 'box-photo.jpg');

        $content = $this->fetchImageSitemap($browser);

        $englishUrl = $this->absoluteUrl('en', PuzzleFixture::PUZZLE_500_01);
        $thumbnail = self::getContainer()->get(ImageThumbnailTwigExtension::class)->thumbnailUrl('box-photo.jpg', 'puzzle_medium');

        self::assertStringContainsString(
            sprintf('<url><loc>%s</loc>', $englishUrl),
            $content,
        );
        self::assertStringContainsString(sprintf('<image:loc>%s</image:loc>', $thumbnail), $content);

        // Never the uploaded original: pre-2026 originals can still carry EXIF location data
        self::assertStringNotContainsString('/original/box-photo.jpg', $content);

        // The x-default URL only - no Czech (or other locale) duplicate of the same photo
        self::assertStringNotContainsString(sprintf('<loc>%s</loc>', $this->absoluteUrl('cs', PuzzleFixture::PUZZLE_500_01)), $content);
        self::assertSame(1, substr_count($content, 'box-photo.jpg'));
    }

    public function testEntryCarriesTheLastmodOfThePuzzlePage(): void
    {
        $browser = self::createClient();
        $this->setImage(PuzzleFixture::PUZZLE_500_04, 'lastmod-photo.png');
        $database = self::getContainer()->get(Connection::class);
        // Without solves, the approval is the latest change
        $database->executeStatement(
            'DELETE FROM puzzle_solving_time WHERE puzzle_id = :id',
            ['id' => PuzzleFixture::PUZZLE_500_04],
        );
        $database->executeStatement(
            "UPDATE puzzle SET added_at = '2024-01-01 10:00:00', approved_at = '2025-02-03 10:00:00' WHERE id = :id",
            ['id' => PuzzleFixture::PUZZLE_500_04],
        );

        $content = $this->fetchImageSitemap($browser);

        self::assertStringContainsString(
            sprintf('<url><loc>%s</loc><lastmod>2025-02-03</lastmod>', $this->absoluteUrl('en', PuzzleFixture::PUZZLE_500_04)),
            $content,
        );
    }

    private function setImage(string $puzzleId, string $image): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE puzzle SET image = :image WHERE id = :id',
            ['id' => $puzzleId, 'image' => $image],
        );
    }

    private function fetchImageSitemap(KernelBrowser $browser): string
    {
        $browser->request('GET', '/sitemap-images-1.xml');

        $this->assertResponseIsSuccessful();

        return (string) $browser->getResponse()->getContent();
    }

    private function absoluteUrl(string $locale, string $puzzleId): string
    {
        return self::getContainer()->get(UrlGeneratorInterface::class)->generate('puzzle_detail', [
            '_locale' => $locale,
            'puzzleId' => $puzzleId,
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
