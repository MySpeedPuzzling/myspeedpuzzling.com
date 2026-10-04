<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleIntelligenceFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AddedTrackingRecapControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/tracking-added/' . PuzzleSolvingTimeFixture::TIME_01);

        $this->assertResponseRedirects();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/tracking-added/' . PuzzleSolvingTimeFixture::TIME_01);

        $this->assertResponseIsSuccessful();
    }

    /**
     * The name in the viewer's language under the main title: PLAYER_REGULAR is from Czechia, so on an English page
     * the Czech one - and on a German page the German one
     */
    public function testShowsTheNameInTheViewersLanguage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $puzzle = $entityManager->find(Puzzle::class, PuzzleFixture::PUZZLE_500_01);
        self::assertNotNull($puzzle);
        $puzzle->changeNames($puzzle->name, $puzzle->nameLanguage, PuzzleNames::fromArray([
            ['name' => 'Puzzle One', 'language' => null],
            ['name' => 'Bayerische Romanze', 'language' => 'de'],
            ['name' => 'Hádanka jedna', 'language' => 'cs'],
        ]), new DateTimeImmutable());
        $entityManager->flush();
        $entityManager->clear();

        $browser->request('GET', '/en/tracking-added/' . PuzzleSolvingTimeFixture::TIME_01);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextSame('h3.card-title + p.puzzle-name-local[lang="cs"]', 'Hádanka jedna');

        $browser->request('GET', '/de/tracking-hinzugefuegt/' . PuzzleSolvingTimeFixture::TIME_01);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextSame('h3.card-title + p.puzzle-name-local[lang="de"]', 'Bayerische Romanze');
    }

    public function testCollectionCtaIsShownForPuzzleNotInCollection(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/tracking-added/' . PuzzleIntelligenceFixture::INTEL_TIME_13);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#recap-collection-cta');
    }

    public function testCollectionCtaIsHiddenWhenPuzzleIsAlreadyInCollection(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/tracking-added/' . PuzzleSolvingTimeFixture::TIME_46_RELAX_NO_FINISHED_AT);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('#recap-collection-cta');
    }
}
