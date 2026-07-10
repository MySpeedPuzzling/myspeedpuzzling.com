<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleRedirect;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PuzzleDetailControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
    }

    public function testPuzzleDetailShowsOffersSection(): void
    {
        $browser = self::createClient();

        // PUZZLE_500_01 has SELLSWAP_01 offer in fixtures
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.bi-shop')->count());
    }

    public function testPuzzleDetailHidesOffersSectionWhenNoOffers(): void
    {
        $browser = self::createClient();

        // PUZZLE_1000_04 has no sell/swap items in fixtures
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_04);

        $this->assertResponseIsSuccessful();
        // The offers card section should not be present (no card with bi-shop icon in card-header)
        self::assertCount(0, $crawler->filter('.card-header .bi-shop'));
    }

    public function testPuzzleWithHiddenImageHasNoindexMeta(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_HIDDEN_IMAGE);

        $this->assertResponseIsSuccessful();
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testNormalPuzzleHasIndexMeta(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testUnknownPuzzleReturns404(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle/' . Uuid::uuid7()->toString());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidPuzzleIdReturns404(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle/not-a-uuid');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testMergedPuzzleRedirectsPermanentlyToSurvivor(): void
    {
        $browser = self::createClient();

        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $clock = $container->get(ClockInterface::class);

        $oldPuzzleId = Uuid::uuid7();
        $entityManager->persist(
            new PuzzleRedirect(
                id: Uuid::uuid7(),
                oldPuzzleId: $oldPuzzleId,
                survivorPuzzleId: Uuid::fromString(PuzzleFixture::PUZZLE_500_01),
                createdAt: $clock->now(),
            ),
        );
        $entityManager->flush();

        $browser->request('GET', '/en/puzzle/' . $oldPuzzleId->toString());

        $this->assertResponseRedirects('/en/puzzle/' . PuzzleFixture::PUZZLE_500_01, 301);
    }
}
